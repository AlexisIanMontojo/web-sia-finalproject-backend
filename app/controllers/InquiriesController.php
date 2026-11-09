<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class InquiriesController extends Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->call->model('InquiriesModel', 'inquiries');
        $this->call->model('UsersModel', 'usersModel');
    }

    private function currentUser()
    {
        $claims = $this->api->require_jwt();
        return $this->usersModel->find((int) $claims['sub']);
    }

    private function shape(array $i)
    {
        return [
            'id'         => (int) $i['id'],
            'subject'    => $i['subject'],
            'message'    => $i['message'],
            'status'     => $i['status'],
            'created_at' => $i['created_at'],
        ];
    }

    public function index()
    {
        $this->api->require_method('GET');
        $user = $this->currentUser();

        $this->api->respond([
            'success' => true,
            'data'    => array_map([$this, 'shape'], $this->inquiries->forUser((int) $user['id'])),
        ]);
    }

    public function store()
    {
        $this->api->require_method('POST');
        $user = $this->currentUser();

        $in = $this->api->body();

        $_POST = $in;
        $this->call->library('form_validation');

        $valid = $this->form_validation->validate([
            'subject|Subject' => 'required|max_length[150]',
            'message|Message' => 'required|min_length[10]|max_length[2000]',
        ]);
        $errors = $valid ? [] : $this->form_validation->get_errors();

        if ($errors) {
            $this->api->respond([
                'success' => false,
                'message' => 'Please correct the form.',
                'errors'  => $errors,
            ], 422);
        }

        $this->inquiries->insert([
            'user_id' => (int) $user['id'],
            'name'    => $user['username'],
            'email'   => $user['email'],
            'subject' => trim((string) $in['subject']),
            'message' => trim((string) $in['message']),
            'status'  => 'open',
        ]);

        $this->api->respond([
            'success' => true,
            'message' => 'Your message was sent. The hotel will reply by email.',
        ], 201);
    }
}