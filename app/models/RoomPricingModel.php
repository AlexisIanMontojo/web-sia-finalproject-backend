<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class RoomPricingModel extends Model
{
    protected $table = 'room_pricing';
    protected $primary_key = 'id';

    protected $fillable = [
        'category_id', 'nightly_rate', 'extra_guest_charge', 'cleaning_fee',
        'effective_from', 'effective_until', 'is_active',
    ];
    protected $guarded = ['id'];

    private function withCategory()
    {
        return $this->db->table($this->table)
            ->select('room_pricing.*, room_categories.name AS category_name')
            ->inner_join('room_categories', 'room_categories.id = room_pricing.category_id');
    }

    public function allWithCategory()
    {
        return $this->withCategory()
            ->order_by('room_categories.name', 'ASC')
            ->order_by('room_pricing.effective_from', 'DESC')
            ->get_all();
    }

    public function findWithCategory($id)
    {
        $rows = $this->withCategory()
            ->where('room_pricing.id', (int) $id)
            ->get_all();

        return $rows[0] ?? null;
    }

    /** The active price in effect on a date. Reservations will use this later. */
    public function activeOn($categoryId, $onDate)
    {
        foreach ($this->db->table($this->table)
                    ->where('category_id', (int) $categoryId)
                    ->where('is_active', 1)
                    ->get_all() as $price) {
            $end = $price['effective_until'] ?: '9999-12-31';
            if ($price['effective_from'] <= $onDate && $end >= $onDate) {
                return $price;
            }
        }
        return null;
    }

    /** True if another active price for this category overlaps the range. */
    public function overlaps($categoryId, $from, $until, $ignoreId = 0)
    {
        $newEnd = $until ?: '9999-12-31';

        foreach ($this->db->table($this->table)
                    ->where('category_id', (int) $categoryId)
                    ->where('is_active', 1)
                    ->get_all() as $price) {
            if ((int) $price['id'] === (int) $ignoreId) {
                continue;
            }
            $end = $price['effective_until'] ?: '9999-12-31';
            if ($price['effective_from'] <= $newEnd && $end >= $from) {
                return true;
            }
        }
        return false;
    }

        /** Price breakdown for a stay, or null if no price applies on the check-in date. */
    public function quote($categoryId, $checkIn, $checkOut, $guests, $capacity)
    {
        $price = $this->activeOn($categoryId, $checkIn);
        if (!$price) {
            return null;
        }

        $nights     = (int) round((strtotime($checkOut) - strtotime($checkIn)) / 86400);
        $nightly    = (float) $price['nightly_rate'];
        $extraGuest = max(0, (int) $guests - (int) $capacity) * (float) $price['extra_guest_charge'] * $nights;
        $cleaning   = (float) $price['cleaning_fee'];
        $extras     = round($extraGuest + $cleaning, 2);

        return [
            'nightly_rate'       => $nightly,
            'nights'             => $nights,
            'extra_guest_charges'=> round($extraGuest, 2),
            'cleaning_fee'       => $cleaning,
            'extra_charges'      => $extras,
            'total'              => round($nightly * $nights + $extras, 2),
        ];
    }
}