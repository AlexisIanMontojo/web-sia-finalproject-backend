<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class CustomersController extends Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->call->model('UsersModel', 'usersModel');
    }

    private function shape(array $u)
    {
        return [
            'id'             => (int) $u['id'],
            'username'       => $u['username'],
            'email'          => $u['email'],
            'is_active'      => (bool) $u['is_active'],
            'email_verified' => !empty($u['email_verified_at']),
            'created_at'     => $u['created_at'],
        ];
    }

    private function validateInput(array $in, array $customer)
    {
        $in['username'] = $in['username'] ?? $customer['username'];

        $_POST = $in;
        $this->call->library('form_validation');

        $isValid = $this->form_validation->validate([
            'username|Username' => 'required|max_length[100]',
        ]);

        $errors   = $isValid ? [] : $this->form_validation->get_errors();
        $username = trim((string) $in['username']);

        if ($username !== '' && $this->usersModel->usernameTaken($username, (int) $customer['id'])) {
            $errors[] = 'This username is already taken.';
        }

        if ($errors) {
            $this->api->respond([
                'success' => false,
                'message' => 'Please correct the errors below.',
                'errors'  => $errors,
            ], 422);
        }

        return [
            'username'  => $username,
            'is_active' => filter_var(
                $in['is_active'] ?? $customer['is_active'],
                FILTER_VALIDATE_BOOLEAN
            ) ? 1 : 0,
        ];
    }

    public function index()
    {
        $this->api->require_method('GET');

        $this->api->respond([
            'success' => true,
            'data'    => array_map([$this, 'shape'], $this->usersModel->allCustomers()),
        ]);
    }

    public function update($id)
    {
        $this->api->require_method('PUT');

        $customer = $this->usersModel->findCustomer($id);
        if (!$customer) {
            $this->api->respond_error('Customer not found.', 404);
        }

        $this->usersModel->update((int) $id, $this->validateInput($this->api->body(), $customer));

        $this->api->respond([
            'success' => true,
            'message' => 'Customer updated.',
            'data'    => $this->shape($this->usersModel->findCustomer($id)),
        ]);
    }
}