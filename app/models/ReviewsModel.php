<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class ReviewsModel extends Model
{
    protected $table = 'reviews';
    protected $primary_key = 'id';

    protected $fillable = [
        'reservation_id', 'user_id', 'room_id', 'rating', 'comment', 'is_visible', 'is_featured',
    ];
    protected $guarded = ['id'];

    public function forUser($userId)
    {
        return $this->db->table($this->table)
            ->where('user_id', (int) $userId)
            ->order_by('created_at', 'DESC')
            ->get_all();
    }

    public function findByReservation($reservationId)
    {
        $rows = $this->db->table($this->table)
            ->where('reservation_id', (int) $reservationId)
            ->get_all();

        return $rows[0] ?? null;
    }

    /** Visible reviews for the public page, featured first. */
    public function publicList($limit = 12)
    {
        return $this->db->raw(
            'SELECT rv.id, rv.rating, rv.comment, rv.created_at, u.username,
                    rm.room_number, c.name AS category_name
             FROM reviews rv
             INNER JOIN users u ON u.id = rv.user_id
             INNER JOIN rooms rm ON rm.id = rv.room_id
             INNER JOIN room_categories c ON c.id = rm.category_id
             WHERE rv.is_visible = 1
             ORDER BY rv.is_featured DESC, rv.created_at DESC
             LIMIT ' . (int) $limit
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function summary()
    {
        $row = $this->db->raw(
            'SELECT ROUND(AVG(rating), 1) AS average, COUNT(*) AS total
             FROM reviews
             WHERE is_visible = 1'
        )->fetch(PDO::FETCH_ASSOC);

        return [
            'average' => $row['average'] === null ? null : (float) $row['average'],
            'total'   => (int) $row['total'],
        ];
    }

    public function allForAdmin()
    {
        return $this->db->raw(
            'SELECT rv.*, u.username, u.email, rm.room_number,
                    c.name AS category_name, r.reservation_code
             FROM reviews rv
             INNER JOIN users u ON u.id = rv.user_id
             INNER JOIN rooms rm ON rm.id = rv.room_id
             INNER JOIN room_categories c ON c.id = rm.category_id
             INNER JOIN reservations r ON r.id = rv.reservation_id
             ORDER BY rv.created_at DESC'
        )->fetchAll(PDO::FETCH_ASSOC);
    }
}