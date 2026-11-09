<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class PasswordResetModel extends Model
{
    protected $table = 'password_reset_tokens';
    protected $primary_key = 'id';

    protected $fillable = ['user_id', 'token_hash', 'expires_at', 'used_at'];
    protected $guarded = ['id'];

    public function findValid($tokenHash)
    {
        $rows = $this->db->table($this->table)
            ->where('token_hash', $tokenHash)
            ->where_null('used_at')
            ->where('expires_at', '>', gmdate('Y-m-d H:i:s'))
            ->get_all();

        return $rows[0] ?? null;
    }

    public function invalidateFor($userId)
    {
        return $this->db->table($this->table)
            ->where('user_id', (int) $userId)
            ->where_null('used_at')
            ->update(['used_at' => gmdate('Y-m-d H:i:s')]);
    }

    public function markUsed($id)
    {
        return $this->update((int) $id, ['used_at' => gmdate('Y-m-d H:i:s')]);
    }
}