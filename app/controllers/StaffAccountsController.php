<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class StaffAccountsController extends Controller
{
    const ROLES = ['manager', 'staff'];

    public function __construct()
    {
        parent::__construct();
        $this->call->model('UsersModel', 'usersModel');
    }

    private function shape(array $u)
    {
        return [
            'id'         => (int) $u['id'],
            'username'   => $u['username'],
            'email'      => $u['email'],
            'role'       => $u['role'],
            'is_active'  => (bool) $u['is_active'],
            'created_at' => $u['created_at'],
        ];
    }

    private function validateUsername($username, $ignoreId, array &$errors)
    {
        if ($username === '' || mb_strlen($username) > 100) {
            $errors[] = 'Username is required (100 characters maximum).';
        } elseif ($this->usersModel->usernameTaken($username, (int) $ignoreId)) {
            $errors[] = 'This username is already taken.';
        }
    }

    private function validatePassword($password, array &$errors)
    {
        if (strlen($password) < 8 || strlen($password) > 72) {
            $errors[] = 'Password must be 8 to 72 characters.';
        }
    }

    private function fail(array $errors)
    {
        $this->api->respond([
            'success' => false,
            'message' => 'Please correct the errors below.',
            'errors'  => $errors,
        ], 422);
    }

    public function index()
    {
        $this->api->require_method('GET');

        $this->api->respond([
            'success' => true,
            'data'    => array_map([$this, 'shape'], $this->usersModel->staffAccounts()),
        ]);
    }

    public function store()
    {
        $this->api->require_method('POST');
        $in     = $this->api->body();
        $errors = [];

        $username = trim((string) ($in['username'] ?? ''));
        $email    = strtolower(trim((string) ($in['email'] ?? '')));
        $password = (string) ($in['password'] ?? '');
        $role     = (string) ($in['role'] ?? '');

        $this->validateUsername($username, 0, $errors);

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Enter a valid email address.';
        } elseif ($this->usersModel->findByEmail($email)) {
            $errors[] = 'This email is already registered.';
        }

        $this->validatePassword($password, $errors);

        if (!in_array($role, self::ROLES, true)) {
            $errors[] = 'Choose a role: manager or staff.';
        }

        if ($errors) {
            $this->fail($errors);
        }

        // Accounts created by the admin are trusted, so the email is marked verified.
        $id = $this->usersModel->insert([
            'username'          => $username,
            'email'             => $email,
            'password'          => password_hash($password, PASSWORD_DEFAULT),
            'role'              => $role,
            'is_active'         => 1,
            'email_verified_at' => date('Y-m-d H:i:s'),
        ]);

        $this->api->respond([
            'success' => true,
            'message' => 'Account created.',
            'data'    => $this->shape($this->usersModel->findStaff((int) $id)),
        ], 201);
    }

    public function update($id)
    {
        $this->api->require_method('PUT');

        $staff = $this->usersModel->findStaff((int) $id);
        if (!$staff) {
            $this->api->respond_error('Account not found.', 404);
        }

        $in     = $this->api->body();
        $errors = [];

        $username = trim((string) ($in['username'] ?? $staff['username']));
        $role     = (string) ($in['role'] ?? $staff['role']);
        $active   = filter_var($in['is_active'] ?? $staff['is_active'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;

        $this->validateUsername($username, (int) $id, $errors);

        if (!in_array($role, self::ROLES, true)) {
            $errors[] = 'Choose a role: manager or staff.';
        }

        if ($errors) {
            $this->fail($errors);
        }

        $this->usersModel->updateStaff((int) $id, [
            'username'  => $username,
            'role'      => $role,
            'is_active' => $active,
        ]);

        // A disabled account is signed out everywhere, immediately.
        if (!$active) {
            $this->usersModel->revokeSessions((int) $id);
        }

        $this->api->respond([
            'success' => true,
            'message' => 'Account updated.',
            'data'    => $this->shape($this->usersModel->findStaff((int) $id)),
        ]);
    }

    public function resetPassword($id)
    {
        $this->api->require_method('PUT');

        if (!$this->usersModel->findStaff((int) $id)) {
            $this->api->respond_error('Account not found.', 404);
        }

        $password = (string) ($this->api->body()['password'] ?? '');

        $errors = [];
        $this->validatePassword($password, $errors);
        if ($errors) {
            $this->fail($errors);
        }

        $this->usersModel->updateStaff((int) $id, [
            'password' => password_hash($password, PASSWORD_DEFAULT),
        ]);

        // The old password's sessions end, so the account must sign in again.
        $this->usersModel->revokeSessions((int) $id);

        $this->api->respond(['success' => true, 'message' => 'Password reset. The user must sign in again.']);
    }
}