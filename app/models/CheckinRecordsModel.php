<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class CheckinRecordsModel extends Model
{
    protected $table = 'checkin_checkout_records';
    protected $primary_key = 'id';

    protected $fillable = [
        'reservation_id', 'checked_in_by', 'checked_in_at', 'checkin_notes',
        'checked_out_by', 'checked_out_at', 'checkout_notes',
    ];
    protected $guarded = ['id'];

    public function forReservation($reservationId)
    {
        $rows = $this->db->table($this->table)
            ->where('reservation_id', (int) $reservationId)
            ->get_all();

        return $rows[0] ?? null;
    }
}