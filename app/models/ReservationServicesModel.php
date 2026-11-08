<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class ReservationServicesModel extends Model
{
    protected $table = 'reservation_services';
    protected $primary_key = 'id';

    protected $fillable = [
        'reservation_id', 'service_id', 'quantity', 'unit_price', 'total_amount',
        'scheduled_at', 'ends_at', 'notes', 'status', 'billing_status', 'billed_at',
    ];
    protected $guarded = ['id'];

    /** Requests that hold capacity for a time slot. */
    const HOLDING = ['requested', 'confirmed'];

    public function forReservation($reservationId)
    {
        return $this->db->raw(
            'SELECT rs.*, sc.name, sc.category, sc.unit
             FROM reservation_services rs
             INNER JOIN service_catalog sc ON sc.id = rs.service_id
             WHERE rs.reservation_id = ?
             ORDER BY rs.created_at DESC',
            [(int) $reservationId]
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    /** One request, only if it belongs to the signed-in guest. */
    public function findOwned($id, $userId)
    {
        $rows = $this->db->raw(
            'SELECT rs.*
             FROM reservation_services rs
             INNER JOIN reservations r ON r.id = rs.reservation_id
             WHERE rs.id = ? AND r.user_id = ?
             LIMIT 1',
            [(int) $id, (int) $userId]
        )->fetchAll(PDO::FETCH_ASSOC);

        return $rows[0] ?? null;
    }

    /** How many held requests overlap the time slot for a service. */
    public function bookedCount($serviceId, $start, $end)
    {
        return (int) $this->db->table($this->table)
            ->where('service_id', (int) $serviceId)
            ->in('status', self::HOLDING)
            ->where('scheduled_at', '<', $end)
            ->where('ends_at', '>', $start)
            ->count();
    }

    /** Staff view: requests with the guest, room, and service details. */
    public function adminList($status)
    {
        $sql = 'SELECT rs.*, sc.name, sc.category, sc.unit,
                       r.reservation_code, r.check_in_date, r.check_out_date,
                       u.username, rm.room_number
                FROM reservation_services rs
                INNER JOIN service_catalog sc ON sc.id = rs.service_id
                INNER JOIN reservations r ON r.id = rs.reservation_id
                INNER JOIN users u ON u.id = r.user_id
                INNER JOIN rooms rm ON rm.id = r.room_id';
        $params = [];

        if ($status !== 'all') {
            $sql .= ' WHERE rs.status = ?';
            $params[] = $status;
        }

        $sql .= ' ORDER BY COALESCE(rs.scheduled_at, rs.created_at) ASC';

        return $this->db->raw($sql, $params)->fetchAll(PDO::FETCH_ASSOC);
    }
}