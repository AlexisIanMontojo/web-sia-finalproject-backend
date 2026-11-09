<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class InquiriesModel extends Model
{
    protected $table = 'inquiries';
    protected $primary_key = 'id';

    protected $fillable = ['user_id', 'name', 'email', 'subject', 'message', 'status'];
    protected $guarded = ['id'];

    public function forUser($userId)
    {
        return $this->db->table($this->table)
            ->where('user_id', (int) $userId)
            ->order_by('created_at', 'DESC')
            ->get_all();
    }
}