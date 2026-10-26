<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class UsersModel extends Model
{
    protected $table = 'users';
    protected $primary_key = 'id';

    protected $fillable = [
        'username', 'email', 'password', 'role', 'is_active', 'email_verified_at',
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

    public function createCustomer(array $data)
    {
        return $this->insert($data);
    }

    public function usernameTaken($username, $ignoreId = 0)
    {
        foreach ($this->db->table($this->table)->where('username', $username)->get_all() as $row) {
            if ((int) $row['id'] !== (int) $ignoreId) {
                return true;
            }
        }
        return false;
    }

    public function allCustomers()
    {
        return $this->db->table($this->table)
            ->select('id, username, email, is_active, email_verified_at, created_at')
            ->where('role', 'customer')
            ->order_by('created_at', 'DESC')
            ->get_all();
    }

    public function findCustomer($id)
    {
        $user = $this->find((int) $id);
        return ($user && $user['role'] === 'customer') ? $user : null;
    }

    public function countCustomers()
    {
        return (int) $this->db->table($this->table)->where('role', 'customer')->count();
    }
}