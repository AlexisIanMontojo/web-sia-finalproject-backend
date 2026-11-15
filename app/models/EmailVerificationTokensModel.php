<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class EmailVerificationTokensModel extends Model
{
    protected $table = 'email_verification_tokens';
    protected $primary_key = 'id';

    protected $fillable = [
        'user_id',
        'token_hash',
        'expires_at',
        'used_at'
    ];

    protected $guarded = ['id'];

    public function findByHash($hash)
    {
        return $this->find_by('token_hash', $hash);
    }

    public function createToken($data)
    {
        return $this->insert($data);
    }

    public function invalidateFor($userId)
    {
        return $this->db->table($this->table)
            ->where('user_id', (int) $userId)
            ->where_null('used_at')
            ->update(['used_at' => gmdate('Y-m-d H:i:s')]);
    }
}
