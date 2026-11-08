<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class CustomerMessagesController extends Controller
{
    const CHANNELS = ['in_app', 'email'];

    public function __construct()
    {
        parent::__construct();
        $this->call->model('MessagesModel', 'messages');
        $this->call->model('UsersModel', 'usersModel');
        $this->call->model('HotelInfoModel', 'hotel');
    }

    private function currentUserId()
    {
        $claims = $this->api->require_jwt();
        return (int) $claims['sub'];
    }

    private function shape(array $m)
    {
        return [
            'id'           => (int) $m['id'],
            'channel'      => $m['channel'],
            'sender'       => $m['sender'],
            'subject'      => $m['subject'],
            'body'         => $m['body'],
            'email_status' => $m['email_status'],
            'is_read'      => (bool) $m['is_read'],
            'created_at'   => $m['created_at'],
        ];
    }

    private function thread(array $root)
    {
        $root = $this->messages->find((int) $root['id']);

        return array_merge($this->shape($root), [
            'replies' => array_map([$this, 'shape'], $this->messages->repliesFor((int) $root['id'])),
        ]);
    }

    /** GET /api/customer/messages */
    public function index()
    {
        $this->api->require_method('GET');
        $userId = $this->currentUserId();

        $data = array_map(fn ($root) => $this->thread($root), $this->messages->threadsForUser($userId));

        $this->api->respond(['success' => true, 'data' => $data]);
    }

    /** GET /api/customer/messages/unread-count */
    public function unreadCount()
    {
        $this->api->require_method('GET');

        $this->api->respond([
            'success' => true,
            'unread'  => $this->messages->unreadForGuest($this->currentUserId()),
        ]);
    }

    /** PUT /api/customer/messages/read */
    public function markRead()
    {
        $this->api->require_method('PUT');

        $this->messages->markAllReadForGuest($this->currentUserId());

        $this->api->respond(['success' => true, 'unread' => 0]);
    }

    /** POST /api/customer/messages */
    public function store()
    {
        $this->api->require_method('POST');
        $userId  = $this->currentUserId();
        $in      = $this->api->body();
        $channel = (string) ($in['channel'] ?? 'in_app');

        $_POST = $in;
        $this->call->library('form_validation');

        $valid = $this->form_validation->validate([
            'subject|Subject' => 'required|max_length[150]',
            'message|Message' => 'required|max_length[2000]',
        ]);

        $errors = $valid ? [] : $this->form_validation->get_errors();

        if (trim((string) ($in['message'] ?? '')) === '') {
            $errors[] = 'Please write a message.';
        }

        if (!in_array($channel, self::CHANNELS, true)) {
            $errors[] = 'Choose how to send: in the app or by email.';
        }

        if ($errors) {
            $this->api->respond([
                'success' => false,
                'message' => 'Please correct the message.',
                'errors'  => $errors,
            ], 422);
        }

        $user    = $this->usersModel->find($userId);
        $hotel   = $this->hotel->current();
        $subject = trim((string) $in['subject']);
        $message = trim((string) $in['message']);

        $status = 'not_sent';

        if ($channel === 'email') {
            $inbox  = $this->hotel->inboxEmail();
            $status = $inbox
                ? $this->messages->deliverEmail(
                    ['email' => $inbox, 'name' => $hotel['name'] ?? 'Hotel'],
                    '[Guest message] ' . $subject,
                    "From: {$user['username']} <{$user['email']}>\n\n{$message}",
                    ['email' => $user['email'], 'name' => $user['username']]
                )
                : 'not_configured';
        }

        $id = $this->messages->insert([
            'user_id'      => $userId,
            'parent_id'    => null,
            'channel'      => $channel,
            'sender'       => 'customer',
            'subject'      => $subject,
            'body'         => $message,
            'is_read'      => 0,
            'read_at'      => null,
            'email_status' => $status,
            'guest_read'   => 1,
        ]);

        $this->api->respond([
            'success' => true,
            'message' => $channel === 'email'
                ? 'Your message was emailed to the hotel and saved in your conversations.'
                : 'Your message was sent to the hotel.',
            'data'    => $this->thread($this->messages->find((int) $id)),
        ], 201);
    }

    /** POST /api/customer/messages/{id}/reply */
    public function reply($id)
    {
        $this->api->require_method('POST');
        $userId = $this->currentUserId();

        $root = $this->messages->findOwnedRoot((int) $id, $userId);
        if (!$root) {
            $this->api->respond_error('Conversation not found.', 404);
        }

        $in      = $this->api->body();
        $message = trim((string) ($in['message'] ?? ''));

        if ($message === '' || mb_strlen($message) > 2000) {
            $this->api->respond_error('Write a reply of up to 2000 characters.', 422);
        }

        $user   = $this->usersModel->find($userId);
        $status = 'not_sent';

        if ($root['channel'] === 'email') {
            $inbox  = $this->hotel->inboxEmail();
            $status = $inbox
                ? $this->messages->deliverEmail(
                    ['email' => $inbox, 'name' => 'Hotel'],
                    '[Guest reply] ' . $root['subject'],
                    "From: {$user['username']} <{$user['email']}>\n\n{$message}",
                    ['email' => $user['email'], 'name' => $user['username']]
                )
                : 'not_configured';
        }

        $this->messages->insert([
            'user_id'      => $userId,
            'parent_id'    => (int) $root['id'],
            'channel'      => $root['channel'],
            'sender'       => 'customer',
            'subject'      => null,
            'body'         => $message,
            'is_read'      => 1,
            'read_at'      => null,
            'email_status' => $status,
            'guest_read'   => 1,
        ]);

        // A customer reply makes the conversation unread for the hotel again.
        $this->messages->update((int) $root['id'], ['is_read' => 0, 'read_at' => null]);

        $this->api->respond([
            'success' => true,
            'message' => 'Reply sent.',
            'data'    => $this->thread($this->messages->find((int) $root['id'])),
        ], 201);
    }
}