<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class RefreshTokensModel extends Model
{
    protected $table = 'refresh_tokens';
    protected $primary_key = 'id';

    protected $fillable = [
        'user_id',
        'token_hash',
        'jti',
        'expires_at',
        'revoked_at'
    ];

    protected $guarded = ['id'];

    public function findByHash($tokenHash)
    {
        return $this->find_by('token_hash', $tokenHash);
    }
}