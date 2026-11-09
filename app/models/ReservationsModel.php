<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class ReservationsModel extends Model
{
    protected $table = 'reservations';
    protected $primary_key = 'id';

    protected $fillable = [
        'reservation_code', 'user_id', 'room_id', 'check_in_date', 'check_out_date',
        'adults', 'children', 'special_requests', 'nightly_rate', 'extra_charges',
        'total_amount', 'status', 'cancellation_reason',
    ];
    protected $guarded = ['id'];

    /** Statuses that hold a room for their nights. */
    const HOLDING = ['pending', 'confirmed', 'checked_in'];

    public static function isDate($value)
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return false;
        }
        [$y, $m, $d] = array_map('intval', explode('-', $value));
        return checkdate($m, $d, $y);
    }

    public static function isCancellable(array $reservation)
    {
        return in_array($reservation['status'], ['pending', 'confirmed'], true)
            && $reservation['check_in_date'] > date('Y-m-d');
    }

    /** Rooms the admin has blocked for any night in the range. */
    private function blockedRoomIds($from, $to)
    {
        $rows = $this->db->table('room_blocks')
            ->where('start_date', '<', $to)
            ->where('end_date', '>', $from)
            ->get_all();

        return array_map(fn ($r) => (int) $r['room_id'], $rows);
    }

    /** Reservations that hold a room for any night in the range. */
    public function holdingBetween($from, $to)
    {
        return $this->db->table($this->table)
            ->where('check_in_date', '<', $to)
            ->where('check_out_date', '>', $from)
            ->in('status', self::HOLDING)
            ->get_all();
    }

    /** Reservations on one room that conflict with the range. Used before blocking. */
    public function conflictsForRoom($roomId, $from, $to)
    {
        return $this->db->table($this->table)
            ->where('room_id', (int) $roomId)
            ->where('check_in_date', '<', $to)
            ->where('check_out_date', '>', $from)
            ->in('status', self::HOLDING)
            ->get_all();
    }

    public function roomIsFree($roomId, $checkIn, $checkOut, $excludeId = 0)
    {
        if (in_array((int) $roomId, $this->blockedRoomIds($checkIn, $checkOut), true)) {
            return false;
        }

        $busy = $this->db->table($this->table)
            ->where('room_id', (int) $roomId)
            ->where('id', '<>', (int) $excludeId)
            ->where('check_in_date', '<', $checkOut)
            ->where('check_out_date', '>', $checkIn)
            ->in('status', self::HOLDING)
            ->count();

        return (int) $busy === 0;
    }

    /** Active, available rooms of a type that fit the guests and are free for the dates. */
    public function availableRooms($categoryId, $checkIn, $checkOut, $guests)
    {
        $booked = array_map(
            fn ($r) => (int) $r['room_id'],
            $this->holdingBetween($checkIn, $checkOut)
        );

        $unavailable = array_merge($booked, $this->blockedRoomIds($checkIn, $checkOut));

        $rooms = $this->db->table('rooms')
            ->where('category_id', (int) $categoryId)
            ->where('is_active', 1)
            ->where('status', 'available')
            ->where('capacity', '>=', (int) $guests)
            ->order_by('room_number', 'ASC')
            ->get_all();

        return array_values(array_filter(
            $rooms,
            fn ($room) => !in_array((int) $room['id'], $unavailable, true)
        ));
    }

    public function forUser($userId)
    {
        return $this->db->table($this->table)
            ->where('user_id', (int) $userId)
            ->order_by('check_in_date', 'DESC')
            ->get_all();
    }

    public function findOwned($id, $userId)
    {
        $rows = $this->db->table($this->table)
            ->where('id', (int) $id)
            ->where('user_id', (int) $userId)
            ->get_all();

        return $rows[0] ?? null;
    }

    public function addHistory($reservationId, $oldStatus, $newStatus, $userId, $remarks)
    {
        $this->db->table('reservation_status_history')->insert([
            'reservation_id' => (int) $reservationId,
            'old_status'     => $oldStatus,
            'new_status'     => $newStatus,
            'changed_by'     => (int) $userId,
            'remarks'        => $remarks,
        ]);
    }

        /** Reservations for the admin list, with room, category, and guest details. */
    public function allForAdmin($status = '')
    {
        $sql = 'SELECT r.*, rm.room_number, c.name AS category_name,
                       u.username, u.email
                FROM reservations r
                INNER JOIN rooms rm ON rm.id = r.room_id
                INNER JOIN room_categories c ON c.id = rm.category_id
                INNER JOIN users u ON u.id = r.user_id';

        $params = [];
        if ($status !== '') {
            $sql .= ' WHERE r.status = ?';
            $params[] = $status;
        }
        $sql .= ' ORDER BY r.check_in_date ASC, r.id ASC';

        return $this->db->raw($sql, $params)->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Moves a reservation to a new status and records the change, in one transaction. */
    public function changeStatus($id, $from, $to, $adminId, $remarks, array $extra = [])
    {
        $this->transaction();

        try {
            $this->update((int) $id, array_merge(['status' => $to], $extra));
            $this->addHistory($id, $from, $to, $adminId, $remarks);
            $this->commit();
            return true;
        } catch (\Throwable $e) {
            $this->rollback();
            error_log('Reservation status change failed: ' . get_class($e) . ': ' . $e->getMessage());
            return false;
        }
    }

        /** Room ids that cannot be sold for the range: held by a booking or blocked by the admin. */
    public function unavailableRoomIds($from, $to)
    {
        $ids = array_map(
            fn ($r) => (int) $r['room_id'],
            $this->holdingBetween($from, $to)
        );

        $blocks = $this->db->table('room_blocks')
            ->where('start_date', '<', $to)
            ->where('end_date', '>', $from)
            ->get_all();

        foreach ($blocks as $b) {
            $ids[] = (int) $b['room_id'];
        }

        return array_values(array_unique($ids));
    }

        /** Moves a reservation to a new status and records the change in one transaction. */
    public function transition($id, $from, $to, $adminId, $remarks, array $extra = [])
    {
        $this->transaction();

        try {
            $this->update((int) $id, array_merge(['status' => $to], $extra));
            $this->addHistory($id, $from, $to, $adminId, $remarks);
            $this->commit();
            return true;
        } catch (\Throwable $e) {
            $this->rollback();
            error_log('Status change failed: ' . get_class($e) . ': ' . $e->getMessage());
            return false;
        }
    }
}