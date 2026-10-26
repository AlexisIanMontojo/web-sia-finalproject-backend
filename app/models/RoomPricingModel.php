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