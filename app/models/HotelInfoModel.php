<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class HotelInfoModel extends Model
{
    protected $table = 'hotel_info';
    protected $primary_key = 'id';

    protected $fillable = [
        'name', 'address', 'phone', 'email', 'check_in_time', 'check_out_time',
        'amenities', 'policies', 'latitude', 'longitude',
    ];
    protected $guarded = ['id'];

    /** The hotel has one information row. */
    const ROW_ID = 1;

    public function current()
    {
        $row = $this->find(self::ROW_ID);

        if (!$row) {
            return null;
        }

        $row['amenities'] = json_decode($row['amenities'] ?? '[]', true) ?: [];
        $row['policies']  = json_decode($row['policies'] ?? '[]', true) ?: [];

        return $row;
    }

    public function saveDetails(array $data)
    {
        return $this->update(self::ROW_ID, $data);
    }

        /** Where guest messages are delivered: the hotel's email, or the sender address as a fallback. */
    public function inboxEmail()
    {
        $h = $this->find(self::ROW_ID);

        if ($h && !empty($h['email'])) {
            return $h['email'];
        }

        return getenv('BREVO_SENDER_EMAIL') ?: null;
    }
}