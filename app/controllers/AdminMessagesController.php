<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class AdminMessagesController extends Controller
{
    const FILTERS = ['all', 'unread', 'in_app', 'email'];

    public function __construct()
    {
        parent::__construct();
        $this->call->model('MessagesModel', 'messages');
        $this->call->model('UsersModel', 'usersModel');
        $this->call->model('HotelInfoModel', 'hotel');
    }

    private function row(array $r)
    {
        return [
            'id'            => (int) $r['id'],
            'subject'       => $r['subject'],
            'channel'       => $r['channel'],
            'is_read'       => (bool) $r['is_read'],
            'email_status'  => $r['email_status'],
            'guest_name'    => $r['username'],
            'guest_email'   => $r['email'],
            'reply_count'   => (int) $r['reply_count'],
            'last_activity' => $r['last_activity'],
            'created_at'    => $r['created_at'],
        ];
    }

    private function message(array $m)
    {
        return [
            'id'           => (int) $m['id'],
            'sender'       => $m['sender'],
            'channel'      => $m['channel'],
            'body'         => $m['body'],
            'email_status' => $m['email_status'],
            'created_at'   => $m['created_at'],
        ];
    }

    private function validateReply($in)
    {
        $message = trim((string) ($in['message'] ?? ''));

        if ($message === '' || mb_strlen($message) > 2000) {
            $this->api->respond_error('Write a reply of up to 2000 characters.', 422);
        }

        return $message;
    }

    /** GET /api/admin/messages?filter=all|unread|in_app|email */
    public function index()
    {
        $this->api->require_method('GET');

        $filter = (string) ($_GET['filter'] ?? 'all');
        if (!in_array($filter, self::FILTERS, true)) {
            $this->api->respond_error('Unknown filter.', 422);
        }

        $this->api->respond([
            'success'        => true,
            'unread'         => $this->messages->unreadCount(),
            'awaiting_reply' => $this->messages->awaitingReply(),
            'data'           => array_map([$this, 'row'], $this->messages->inbox($filter)),
        ]);
    }

    /** GET /api/admin/messages/{id} */
    public function thread($id)
    {
        $this->api->require_method('GET');

        $root = $this->messages->findRoot((int) $id);
        if (!$root) {
            $this->api->respond_error('Conversation not found.', 404);
        }

        $guest   = $this->usersModel->find((int) $root['user_id']);
        $replies = $this->messages->repliesFor((int) $root['id']);

        $this->api->respond([
            'success' => true,
            'data'    => [
                'id'          => (int) $root['id'],
                'subject'     => $root['subject'],
                'channel'     => $root['channel'],
                'is_read'     => (bool) $root['is_read'],
                'guest_name'  => $guest['username'] ?? '',
                'guest_email' => $guest['email'] ?? '',
                'messages'    => array_merge(
                    [$this->message($root)],
                    array_map([$this, 'message'], $replies)
                ),
            ],
        ]);
    }

    /** POST /api/admin/messages/{id}/reply */
    public function reply($id)
    {
        $this->api->require_method('POST');

        $root = $this->messages->findRoot((int) $id);
        if (!$root) {
            $this->api->respond_error('Conversation not found.', 404);
        }

        $in      = $this->api->body();
        $message = $this->validateReply($in);
        $guest   = $this->usersModel->find((int) $root['user_id']);
        $status  = 'not_sent';

        if (!empty($in['send_email'])) {
            $inbox  = $this->hotel->inboxEmail();
            $status = ($inbox && $guest)
                ? $this->messages->deliverEmail(
                    ['email' => $guest['email'], 'name' => $guest['username']],
                    'Re: ' . $root['subject'],
                    $message,
                    ['email' => $inbox, 'name' => 'Hotel']
                )
                : 'not_configured';
        }

        $this->messages->insert([
            'user_id'      => (int) $root['user_id'],
            'parent_id'    => (int) $root['id'],
            'channel'      => 'in_app',
            'sender'       => 'hotel',
            'subject'      => null,
            'body'         => $message,
            'is_read'      => 1,
            'read_at'      => null,
            'email_status' => $status,
        ]);

        // The hotel has answered: the conversation is read for the hotel,
        // and unread for the guest, which drives the guest's notification.
        $this->messages->update((int) $root['id'], [
            'is_read'    => 1,
            'read_at'    => date('Y-m-d H:i:s'),
            'guest_read' => 0,
        ]);

        $this->api->respond([
            'success' => true,
            'message' => $status === 'sent' ? 'Reply sent and emailed to the guest.' : 'Reply sent.',
        ], 201);
    }

    /** PUT /api/admin/messages/{id}/read */
    public function setRead($id)
    {
        $this->api->require_method('PUT');

        $root = $this->messages->findRoot((int) $id);
        if (!$root) {
            $this->api->respond_error('Conversation not found.', 404);
        }

        $isRead = filter_var($this->api->body()['is_read'] ?? true, FILTER_VALIDATE_BOOLEAN);

        $this->messages->update((int) $root['id'], [
            'is_read' => $isRead ? 1 : 0,
            'read_at' => $isRead ? date('Y-m-d H:i:s') : null,
        ]);

        $this->api->respond(['success' => true, 'is_read' => $isRead]);
    }
}