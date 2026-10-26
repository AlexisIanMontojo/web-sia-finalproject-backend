<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class RoomsModel extends Model
{
    protected $table = 'rooms';
    protected $primary_key = 'id';

    protected $fillable = [
        'room_number', 'category_id', 'description', 'floor_number',
        'capacity', 'status', 'image_url', 'is_active',
    ];
    protected $guarded = ['id'];

    private function withCategory()
    {
        return $this->db->table($this->table)
            ->select('rooms.*, room_categories.name AS category_name')
            ->inner_join('room_categories', 'room_categories.id = rooms.category_id');
    }

    public function allWithCategory()
    {
        return $this->db->raw(
            'SELECT r.*, c.name AS category_name
             FROM rooms r
             INNER JOIN room_categories c ON c.id = r.category_id
             ORDER BY r.room_number ASC'
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findWithCategory($id)
    {
        $rows = $this->db->raw(
            'SELECT r.*, c.name AS category_name
             FROM rooms r
             INNER JOIN room_categories c ON c.id = r.category_id
             WHERE r.id = ?
             LIMIT 1',
            [(int) $id]
        )->fetchAll(PDO::FETCH_ASSOC);

        return $rows[0] ?? null;
    }

    public function numberTaken($number, $ignoreId = 0)
    {
        foreach ($this->db->table($this->table)->where('room_number', $number)->get_all() as $row) {
            if ((int) $row['id'] !== (int) $ignoreId) {
                return true;
            }
        }
        return false;
    }

    public function countByCategory($categoryId)
    {
        return (int) $this->db->table($this->table)
            ->where('category_id', (int) $categoryId)
            ->count();
    }

    public function countActive()
    {
        return (int) $this->db->table($this->table)->where('is_active', 1)->count();
    }

    public function countByStatus($status)
    {
        return (int) $this->db->table($this->table)
            ->where('is_active', 1)
            ->where('status', $status)
            ->count();
    }

    public function reservationCount($roomId)
    {
        return (int) $this->db->table('reservations')
            ->where('room_id', (int) $roomId)
            ->count();
    }
}