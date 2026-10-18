
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
}
