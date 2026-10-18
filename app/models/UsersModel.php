<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class UsersModel extends Model
{
    protected $table = 'users';
    protected $primary_key = 'id';

    protected $fillable = [
        'username',
        'email',
        'password',
        'role',
        'is_active',
        'email_verified_at'
    ];

    protected $guarded = ['id'];

    public function findByEmail($email)
    {
        return $this->find_by('email', $email);
    }

    public function findByUsername($username)
    {
        return $this->find_by('username', $username);
    }

    public function createCustomer($data)
    {
        return $this->insert($data);
    }
}
