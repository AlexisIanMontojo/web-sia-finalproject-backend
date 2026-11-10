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

    public function allWithCategory()
    {
        return $this->db->raw(
            'SELECT p.*, c.name AS category_name
             FROM room_pricing p
             INNER JOIN room_categories c ON c.id = p.category_id
             ORDER BY c.name ASC, p.effective_from DESC'
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findWithCategory($id)
    {
        $rows = $this->db->raw(
            'SELECT p.*, c.name AS category_name
             FROM room_pricing p
             INNER JOIN room_categories c ON c.id = p.category_id
             WHERE p.id = ?
             LIMIT 1',
            [(int) $id]
        )->fetchAll(PDO::FETCH_ASSOC);

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

    /** Calculate a stay total using the active price for each booked night. */
    public function quote($categoryId, $checkIn, $checkOut, $guests, $capacity)
    {
        $start = \DateTimeImmutable::createFromFormat('!Y-m-d', $checkIn);
        $end   = \DateTimeImmutable::createFromFormat('!Y-m-d', $checkOut);

        if (
            !$start || !$end
            || $start->format('Y-m-d') !== $checkIn
            || $end->format('Y-m-d') !== $checkOut
            || $end <= $start
        ) {
            return null;
        }

        $nights = 0;
        $nightlyTotal = 0.0;
        $extraGuestCharges = 0.0;
        $cleaningFee = null;
        $extraGuests = max(0, (int) $guests - max(1, (int) $capacity));

        for ($night = $start; $night < $end; $night = $night->modify('+1 day')) {
            $price = $this->activeOn($categoryId, $night->format('Y-m-d'));
            if (!$price) {
                return null;
            }

            $nightlyTotal += (float) $price['nightly_rate'];
            $extraGuestCharges += $extraGuests * (float) $price['extra_guest_charge'];
            if ($cleaningFee === null) {
                $cleaningFee = (float) $price['cleaning_fee'];
            }
            $nights++;
        }

        $nightlyRate = round($nightlyTotal / $nights, 2);
        $extraGuestCharges = round($extraGuestCharges, 2);
        $cleaningFee = round($cleaningFee ?? 0, 2);
        $extraCharges = round($extraGuestCharges + $cleaningFee, 2);

        return [
            'nightly_rate'        => $nightlyRate,
            'nights'              => $nights,
            'extra_guest_charges' => $extraGuestCharges,
            'cleaning_fee'        => $cleaningFee,
            'extra_charges'       => $extraCharges,
            'total'               => round($nightlyTotal + $extraCharges, 2),
        ];
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
}