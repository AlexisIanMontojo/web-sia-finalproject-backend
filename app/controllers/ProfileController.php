<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class ProfileController extends Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->call->model('UsersModel', 'usersModel');
    }

    private function currentUser()
    {
        $claims = $this->api->require_jwt();
        return $this->usersModel->find((int) $claims['sub']);
    }

    private function shape(array $u)
    {
        return [
            'id'             => (int) $u['id'],
            'username'       => $u['username'],
            'email'          => $u['email'],
            'email_verified' => !empty($u['email_verified_at']),
            'created_at'     => $u['created_at'],
        ];
    }

    public function show()
    {
        $this->api->require_method('GET');
        $this->api->respond(['success' => true, 'user' => $this->shape($this->currentUser())]);
    }

    public function update()
    {
        $this->api->require_method('PUT');
        $user = $this->currentUser();

        $in = $this->api->body();
        $in['username'] = trim((string) ($in['username'] ?? ''));

        $_POST = $in;
        $this->call->library('form_validation');

        $valid = $this->form_validation->validate([
            'username|Username' => 'required|min_length[3]|max_length[100]',
        ]);
        $errors = $valid ? [] : $this->form_validation->get_errors();

        if (!$errors && $this->usersModel->usernameTaken($in['username'], (int) $user['id'])) {
            $errors[] = 'This username is already taken.';
        }

        if ($errors) {
            $this->api->respond([
                'success' => false,
                'message' => 'Please correct the errors below.',
                'errors'  => $errors,
            ], 422);
        }

        $this->usersModel->update((int) $user['id'], ['username' => $in['username']]);

        $this->api->respond([
            'success' => true,
            'message' => 'Profile updated.',
            'user'    => $this->shape($this->usersModel->find((int) $user['id'])),
        ]);
    }

    public function changePassword()
    {
        $this->api->require_method('PUT');
        $user = $this->currentUser();

        $in = $this->api->body();
        $_POST = $in;
        $this->call->library('form_validation');

        $valid = $this->form_validation->validate([
            'current_password|Current password' => 'required',
            'new_password|New password'         => 'required|min_length[8]|max_length[72]',
            'confirm_password|Confirm password' => 'required|matches[new_password]',
        ]);
        $errors = $valid ? [] : $this->form_validation->get_errors();

        if (!$errors && !password_verify((string) $in['current_password'], $user['password'])) {
            $errors[] = 'Your current password is incorrect.';
        }

        if ($errors) {
            $this->api->respond([
                'success' => false,
                'message' => 'Please correct the errors below.',
                'errors'  => $errors,
            ], 422);
        }

        $this->usersModel->update((int) $user['id'], [
            'password' => password_hash((string) $in['new_password'], PASSWORD_DEFAULT),
        ]);

        $this->api->respond(['success' => true, 'message' => 'Password changed.']);
    }
}