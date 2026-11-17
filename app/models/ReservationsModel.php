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
        $rows = $this->db->raw(
            'SELECT room_id
             FROM room_blocks
             WHERE start_date < ? AND end_date > ?',
            [$to, $from]
        )->fetchAll(PDO::FETCH_ASSOC);

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

    public function acquireRoomLock($roomId)
    {
        $lockName = 'hotel-room-booking-' . (int) $roomId;
        $row = $this->db->raw('SELECT GET_LOCK(?, 5) AS acquired', [$lockName])
            ->fetch(PDO::FETCH_ASSOC);

        return (int) ($row['acquired'] ?? 0) === 1;
    }

    public function releaseRoomLock($roomId)
    {
        $lockName = 'hotel-room-booking-' . (int) $roomId;
        $this->db->raw('SELECT RELEASE_LOCK(?)', [$lockName]);
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

    /** Available room counts by category, using one query for public search results. */
    public function availableCountsByCategory($checkIn, $checkOut, $guests)
    {
        $unavailable = array_fill_keys($this->unavailableRoomIds($checkIn, $checkOut), true);
        $rooms = $this->db->raw(
            "SELECT id, category_id
             FROM rooms
             WHERE is_active = 1 AND status = 'available' AND capacity >= ?",
            [(int) $guests]
        )->fetchAll(PDO::FETCH_ASSOC);

        $counts = [];
        foreach ($rooms as $room) {
            $roomId = (int) $room['id'];
            if (!isset($unavailable[$roomId])) {
                $categoryId = (int) $room['category_id'];
                $counts[$categoryId] = ($counts[$categoryId] ?? 0) + 1;
            }
        }

        return $counts;
    }

    public function forUser($userId)
    {
        return $this->db->raw(
            "SELECT r.*,
                    COALESCE((
                        SELECT SUM(rs.total_amount)
                        FROM reservation_services rs
                        WHERE rs.reservation_id = r.id AND rs.status <> 'cancelled'
                    ), 0) AS service_total,
                    COALESCE((
                        SELECT SUM(rs.total_amount)
                        FROM reservation_services rs
                        WHERE rs.reservation_id = r.id
                          AND rs.status <> 'cancelled'
                          AND rs.billing_status = 'paid'
                    ), 0) AS service_paid,
                    COALESCE((
                        SELECT SUM(p.amount)
                        FROM reservation_payments p
                        WHERE p.reservation_id = r.id
                          AND p.status = 'paid'
                          AND p.kind <> 'service'
                    ), 0) AS reservation_paid
             FROM reservations r
             WHERE r.user_id = ?
             ORDER BY r.check_in_date DESC",
            [(int) $userId]
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findOwned($id, $userId)
    {
        $rows = $this->db->table($this->table)
            ->where('id', (int) $id)
            ->where('user_id', (int) $userId)
            ->get_all();

        return $rows[0] ?? null;
    }

    public function findOwnedWithFinancials($id, $userId)
    {
        $rows = $this->db->raw(
            "SELECT r.*,
                    COALESCE((
                        SELECT SUM(rs.total_amount)
                        FROM reservation_services rs
                        WHERE rs.reservation_id = r.id AND rs.status <> 'cancelled'
                    ), 0) AS service_total,
                    COALESCE((
                        SELECT SUM(rs.total_amount)
                        FROM reservation_services rs
                        WHERE rs.reservation_id = r.id
                          AND rs.status <> 'cancelled'
                          AND rs.billing_status = 'paid'
                    ), 0) AS service_paid,
                    COALESCE((
                        SELECT SUM(p.amount)
                        FROM reservation_payments p
                        WHERE p.reservation_id = r.id
                          AND p.status = 'paid'
                          AND p.kind <> 'service'
                    ), 0) AS reservation_paid
             FROM reservations r
             WHERE r.id = ? AND r.user_id = ?
             LIMIT 1",
            [(int) $id, (int) $userId]
        )->fetchAll(PDO::FETCH_ASSOC);

        return $rows[0] ?? null;
    }

    public function cancelOwned($id, $userId, $reason)
    {
        $this->transaction();

        try {
            $rows = $this->db->raw(
                'SELECT *
                 FROM reservations
                 WHERE id = ? AND user_id = ?
                 FOR UPDATE',
                [(int) $id, (int) $userId]
            )->fetchAll(PDO::FETCH_ASSOC);
            $reservation = $rows[0] ?? null;

            if (!$reservation) {
                $this->rollback();
                return 'not_found';
            }
            if (!self::isCancellable($reservation)) {
                $this->rollback();
                return 'not_cancellable';
            }

            $this->update((int) $id, [
                'status'              => 'cancelled',
                'cancellation_reason' => $reason,
            ]);
            $this->addHistory(
                (int) $id,
                $reservation['status'],
                'cancelled',
                (int) $userId,
                $reason
            );
            $this->commit();
            return true;
        } catch (\Throwable $e) {
            $this->rollback();
            error_log('Reservation cancellation failed: ' . get_class($e) . ': ' . $e->getMessage());
            return false;
        }
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
        $sql = "SELECT r.*, rm.room_number, c.name AS category_name,
                       u.username, u.email,
                       COALESCE((
                           SELECT SUM(rs.total_amount)
                           FROM reservation_services rs
                           WHERE rs.reservation_id = r.id AND rs.status <> 'cancelled'
                       ), 0) AS service_total,
                       COALESCE((
                           SELECT SUM(rs.total_amount)
                           FROM reservation_services rs
                           WHERE rs.reservation_id = r.id
                             AND rs.status <> 'cancelled'
                             AND rs.billing_status = 'paid'
                       ), 0) AS service_paid,
                       COALESCE((
                           SELECT SUM(p.amount)
                           FROM reservation_payments p
                           WHERE p.reservation_id = r.id
                             AND p.status = 'paid'
                             AND p.kind <> 'service'
                       ), 0) AS reservation_paid
                FROM reservations r
                INNER JOIN rooms rm ON rm.id = r.room_id
                INNER JOIN room_categories c ON c.id = rm.category_id
                INNER JOIN users u ON u.id = r.user_id";

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
            $updated = $this->db->table($this->table)
                ->where('id', (int) $id)
                ->where('status', $from)
                ->update(array_merge(['status' => $to], $extra));
            if (!$updated) {
                $this->rollback();
                return 'stale';
            }

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
        $statusPlaceholders = implode(',', array_fill(0, count(self::HOLDING), '?'));
        $booked = $this->db->raw(
            'SELECT DISTINCT room_id
             FROM reservations
             WHERE status IN (' . $statusPlaceholders . ')
               AND check_in_date < ? AND check_out_date > ?',
            array_merge(self::HOLDING, [$to, $from])
        )->fetchAll(PDO::FETCH_ASSOC);
        $ids = array_map(fn ($r) => (int) $r['room_id'], $booked);

        return array_values(array_unique(array_merge($ids, $this->blockedRoomIds($from, $to))));
    }

        /** Moves a reservation to a new status and records the change in one transaction. */
    public function transition(
        $id,
        $from,
        $to,
        $adminId,
        $remarks,
        array $extra = [],
        ?callable $afterTransition = null
    ) {
        $this->transaction();

        try {
            $updated = $this->db->table($this->table)
                ->where('id', (int) $id)
                ->where('status', $from)
                ->update(array_merge(['status' => $to], $extra));
            if (!$updated) {
                $this->rollback();
                return 'stale';
            }

            $this->addHistory($id, $from, $to, $adminId, $remarks);
            if ($afterTransition !== null) {
                $afterTransition();
            }
            $this->commit();
            return true;
        } catch (\Throwable $e) {
            $this->rollback();
            error_log('Status change failed: ' . get_class($e) . ': ' . $e->getMessage());
            return false;
        }
    }
}