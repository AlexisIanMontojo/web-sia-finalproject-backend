<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class MessagesModel extends Model
{
    protected $table = 'messages';
    protected $primary_key = 'id';

    protected $fillable = [
        'user_id', 'parent_id', 'channel', 'sender', 'subject', 'body',
        'is_read', 'read_at', 'email_status', 'guest_read',
    ];
    protected $guarded = ['id'];

    public function threadsForUser($userId)
    {
        return $this->db->table($this->table)
            ->where('user_id', (int) $userId)
            ->where_null('parent_id')
            ->order_by('created_at', 'DESC')
            ->get_all();
    }

    public function repliesFor($rootId)
    {
        return $this->db->table($this->table)
            ->where('parent_id', (int) $rootId)
            ->order_by('created_at', 'ASC')
            ->get_all();
    }

    public function findRoot($id)
    {
        $rows = $this->db->table($this->table)
            ->where('id', (int) $id)
            ->where_null('parent_id')
            ->get_all();

        return $rows[0] ?? null;
    }

    public function findOwnedRoot($id, $userId)
    {
        $rows = $this->db->table($this->table)
            ->where('id', (int) $id)
            ->where('user_id', (int) $userId)
            ->where_null('parent_id')
            ->get_all();

        return $rows[0] ?? null;
    }

    /** Conversations the hotel has not opened yet. */
    public function unreadCount()
    {
        return (int) $this->db->table($this->table)
            ->where_null('parent_id')
            ->where('is_read', 0)
            ->count();
    }

    /**
     * Conversations whose latest message came from the guest, so the hotel still owes a reply.
     * Counted per conversation.
     */
    public function awaitingReply()
    {
        return (int) $this->db->raw(
            "SELECT COUNT(*) AS n
             FROM messages m
             WHERE m.parent_id IS NULL
               AND COALESCE(
                     (SELECT r.sender
                        FROM messages r
                       WHERE r.parent_id = m.id
                       ORDER BY r.created_at DESC, r.id DESC
                       LIMIT 1),
                     m.sender
                   ) = 'customer'",
            []
        )->fetch(PDO::FETCH_ASSOC)['n'];
    }

    /** Conversations where the hotel has replied and the guest has not read it yet. */
    public function unreadForGuest($userId)
    {
        return (int) $this->db->raw(
            'SELECT COUNT(*) AS n
             FROM messages
             WHERE user_id = ?
               AND parent_id IS NULL
               AND guest_read = 0',
            [(int) $userId]
        )->fetch(PDO::FETCH_ASSOC)['n'];
    }

    public function markAllReadForGuest($userId)
    {
        return $this->db->raw(
            'UPDATE messages
             SET guest_read = 1
             WHERE user_id = ?
               AND parent_id IS NULL
               AND guest_read = 0',
            [(int) $userId]
        );
    }

    /** Conversations for the admin inbox, unread first, newest activity first. */
    public function inbox($filter)
    {
        $sql = 'SELECT m.id, m.subject, m.channel, m.is_read, m.email_status, m.created_at,
                       u.username, u.email,
                       (SELECT COUNT(*) FROM messages r WHERE r.parent_id = m.id) AS reply_count,
                       (SELECT MAX(x.created_at) FROM messages x
                         WHERE x.id = m.id OR x.parent_id = m.id) AS last_activity
                FROM messages m
                INNER JOIN users u ON u.id = m.user_id
                WHERE m.parent_id IS NULL';

        if ($filter === 'unread') {
            $sql .= ' AND m.is_read = 0';
        } elseif ($filter === 'in_app') {
            $sql .= " AND m.channel = 'in_app'";
        } elseif ($filter === 'email') {
            $sql .= " AND m.channel = 'email'";
        }

        $sql .= ' ORDER BY m.is_read ASC, last_activity DESC';

        return $this->db->raw($sql, [])->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Reads an environment value from $_ENV first, since Dotenv fills that rather than getenv(). */
    private function env($name, $default = null)
    {
        $value = $_ENV[$name] ?? getenv($name);

        return ($value === false || $value === null || $value === '') ? $default : $value;
    }

    /**
     * Sends a message through Brevo's transactional email API.
     * Returns 'sent', 'failed', or 'not_configured' for the message's email_status.
     */
    public function deliverEmail(array $to, $subject, $text, array $replyTo = null)
    {
        $apiKey      = $this->env('BREVO_API_KEY');
        $senderEmail = $this->env('BREVO_SENDER_EMAIL');
        $senderName  = $this->env('BREVO_SENDER_NAME', 'Hotel Reservation System');

        if (!$apiKey || !$senderEmail) {
            error_log('Brevo email skipped: BREVO_API_KEY or BREVO_SENDER_EMAIL is not set.');
            return 'not_configured';
        }

        $payload = [
            'sender'      => ['name' => $senderName, 'email' => $senderEmail],
            'to'          => [$to],
            'subject'     => (string) $subject,
            'textContent' => (string) $text,
            'htmlContent' => '<div>' . nl2br(htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8')) . '</div>',
        ];

        if ($replyTo) {
            $payload['replyTo'] = $replyTo;
        }

        $ch = curl_init('https://api.brevo.com/v3/smtp/email');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => [
                'api-key: ' . $apiKey,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_POSTFIELDS     => json_encode($payload),
        ]);

        $response = curl_exec($ch);
        $status   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($response === false || $status < 200 || $status >= 300) {
            error_log("Brevo send failed: HTTP {$status} {$error} {$response}");
            return 'failed';
        }

        return 'sent';
    }
}