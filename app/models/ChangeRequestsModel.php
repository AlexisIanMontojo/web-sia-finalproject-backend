<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class ChangeRequestsModel extends Model
{
    protected $table = 'booking_change_requests';
    protected $primary_key = 'id';

    protected $fillable = [
        'reservation_id', 'user_id', 'requested_check_in', 'requested_check_out',
        'requested_guests', 'reason', 'status',
    ];
    protected $guarded = ['id'];

    public function hasPendingFor($reservationId)
    {
        $count = $this->db->table($this->table)
            ->where('reservation_id', (int) $reservationId)
            ->where('status', 'pending')
            ->count();

        return (int) $count > 0;
    }

    public function forUser($userId)
    {
        return $this->db->table($this->table)
            ->where('user_id', (int) $userId)
            ->order_by('created_at', 'DESC')
            ->get_all();
    }
}