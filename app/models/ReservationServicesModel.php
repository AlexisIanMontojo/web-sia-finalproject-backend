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

    public function totalForReservation($reservationId)
    {
        $row = $this->db->raw(
            "SELECT COALESCE(SUM(total_amount), 0) AS total
             FROM reservation_services
             WHERE reservation_id = ? AND status <> 'cancelled'",
            [(int) $reservationId]
        )->fetch(PDO::FETCH_ASSOC);

        return (float) ($row['total'] ?? 0);
    }

    public function paidTotalForReservation($reservationId)
    {
        $row = $this->db->raw(
            "SELECT COALESCE(SUM(total_amount), 0) AS total
             FROM reservation_services
             WHERE reservation_id = ?
               AND status <> 'cancelled'
               AND billing_status = 'paid'",
            [(int) $reservationId]
        )->fetch(PDO::FETCH_ASSOC);

        return (float) ($row['total'] ?? 0);
    }

    public function findByIdempotencyKey($key)
    {
        $rows = $this->db->raw(
            'SELECT rs.*, sc.name, sc.category, sc.unit, r.user_id
             FROM reservation_service_requests req
             INNER JOIN reservation_services rs ON rs.id = req.reservation_service_id
             INNER JOIN service_catalog sc ON sc.id = rs.service_id
             INNER JOIN reservations r ON r.id = rs.reservation_id
             WHERE req.idempotency_key = ?
             LIMIT 1',
            [(string) $key]
        )->fetchAll(PDO::FETCH_ASSOC);

        return $rows[0] ?? null;
    }

    public function acquireIdempotencyLock($key)
    {
        $lockName = 'hotel-service-request-' . substr(hash('sha256', (string) $key), 0, 40);
        $row = $this->db->raw('SELECT GET_LOCK(?, 5) AS acquired', [$lockName])
            ->fetch(PDO::FETCH_ASSOC);

        return (int) ($row['acquired'] ?? 0) === 1;
    }

    public function releaseIdempotencyLock($key)
    {
        $lockName = 'hotel-service-request-' . substr(hash('sha256', (string) $key), 0, 40);
        $this->db->raw('SELECT RELEASE_LOCK(?)', [$lockName]);
    }

    public function recordIdempotencyKey($key, $serviceRequestId)
    {
        $saved = $this->db->table('reservation_service_requests')->insert([
            'idempotency_key'      => (string) $key,
            'reservation_service_id' => (int) $serviceRequestId,
        ]);
        if (!$saved) {
            throw new \RuntimeException('Unable to record the service request key.');
        }
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
                       (SELECT p.provider
                        FROM reservation_payment_services ps
                        INNER JOIN reservation_payments p ON p.id = ps.payment_id
                        WHERE ps.reservation_service_id = rs.id
                          AND p.kind = \'service\' AND p.status = \'pending\'
                        ORDER BY p.id DESC
                        LIMIT 1) AS pending_payment_provider,
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