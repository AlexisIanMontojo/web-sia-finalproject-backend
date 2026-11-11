<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class ReservationPaymentsModel extends Model
{
    protected $table = 'reservation_payments';
    protected $primary_key = 'id';

    protected $fillable = [
        'reservation_id', 'provider', 'kind', 'external_id', 'invoice_id',
        'invoice_url', 'amount', 'status', 'paid_at',
    ];
    protected $guarded = ['id'];

    /** Reads an environment value from $_ENV first, since Dotenv fills that rather than getenv(). */
    public function env($name, $default = null)
    {
        $value = $_ENV[$name] ?? getenv($name);

        return ($value === false || $value === null || $value === '') ? $default : $value;
    }

    public function forReservation($reservationId)
    {
        return $this->db->table($this->table)
            ->where('reservation_id', (int) $reservationId)
            ->order_by('id', 'DESC')
            ->get_all();
    }

    public function findByExternalId($externalId)
    {
        $rows = $this->db->table($this->table)
            ->where('external_id', (string) $externalId)
            ->get_all();

        return $rows[0] ?? null;
    }

    public function paidTotal($reservationId)
    {
        $row = $this->db->raw(
            "SELECT COALESCE(SUM(amount), 0) AS total
             FROM reservation_payments
             WHERE reservation_id = ? AND status = 'paid'",
            [(int) $reservationId]
        )->fetch(PDO::FETCH_ASSOC);

        return (float) ($row['total'] ?? 0);
    }

    public function syncPendingInvoices($reservationId, $externalId = '')
    {
        foreach ($this->forReservation($reservationId) as $payment) {
            if (
                $payment['provider'] !== 'xendit'
                || $payment['status'] !== 'pending'
                || empty($payment['invoice_id'])
                || ($externalId !== '' && $payment['external_id'] !== $externalId)
            ) {
                continue;
            }

            [$code, $invoice] = $this->fetchInvoice($payment['invoice_id']);
            if ($code !== 200) {
                error_log('Could not verify pending Xendit payment ' . $payment['external_id'] . '.');
                continue;
            }

            if (
                (string) ($invoice['external_id'] ?? '') !== (string) $payment['external_id']
                || abs((float) ($invoice['amount'] ?? 0) - (float) $payment['amount']) > 0.01
            ) {
                error_log('Xendit invoice mismatch for ' . $payment['external_id'] . '.');
                continue;
            }

            $status = strtoupper((string) ($invoice['status'] ?? ''));
            if ($status === 'PAID' || $status === 'SETTLED') {
                $paidAt = $invoice['paid_at'] ?? null;
                $paidAtTimestamp = $paidAt ? strtotime($paidAt) : false;
                $this->update((int) $payment['id'], [
                    'status'  => 'paid',
                    'paid_at' => $paidAtTimestamp
                        ? date('Y-m-d H:i:s', $paidAtTimestamp)
                        : date('Y-m-d H:i:s'),
                ]);
            } elseif ($status === 'EXPIRED') {
                $this->update((int) $payment['id'], ['status' => 'expired']);
            }
        }
    }

    public function allForAdmin($status = '')
    {
        $sql = 'SELECT p.id, p.reservation_id, p.provider, p.kind, p.external_id,
                       p.amount, p.status, p.paid_at, p.created_at,
                       r.reservation_code, r.total_amount, r.status AS reservation_status,
                       r.check_in_date, r.check_out_date, rm.room_number,
                       c.name AS category_name, u.username AS guest_name, u.email AS guest_email
                FROM reservation_payments p
                INNER JOIN reservations r ON r.id = p.reservation_id
                INNER JOIN rooms rm ON rm.id = r.room_id
                INNER JOIN room_categories c ON c.id = rm.category_id
                INNER JOIN users u ON u.id = r.user_id';
        $params = [];

        if ($status !== '') {
            $sql .= ' WHERE p.status = ?';
            $params[] = $status;
        }

        $sql .= ' ORDER BY p.created_at DESC, p.id DESC';

        return $this->db->raw($sql, $params)->fetchAll(PDO::FETCH_ASSOC);
    }

    public function xenditRequest($method, $path, $body = null)
    {
        $key = $this->env('XENDIT_SECRET_KEY');

        if (!$key) {
            error_log('Xendit request skipped: XENDIT_SECRET_KEY is not set.');
            return [0, ['message' => 'Payments are not configured.']];
        }

        $ch = curl_init('https://api.xendit.co' . $path);

        $options = [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Basic ' . base64_encode($key . ':'),
                'Content-Type: application/json',
                'Accept: application/json',
            ],
        ];

        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = json_encode($body);
        }

        curl_setopt_array($ch, $options);

        $raw    = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error  = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            error_log('Xendit cURL error: ' . $error);
            return [0, ['message' => 'Could not reach the payment provider.']];
        }

        return [$status, json_decode($raw, true) ?: []];
    }

    public function createInvoice(array $data)
    {
        return $this->xenditRequest('POST', '/v2/invoices', $data);
    }

    public function fetchInvoice($invoiceId)
    {
        return $this->xenditRequest('GET', '/v2/invoices/' . rawurlencode((string) $invoiceId));
    }
}