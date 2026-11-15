<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class AdminReportsModel extends Model
{
    protected $table = 'reservations';
    protected $primary_key = 'id';

    public function overview($from, $toExclusive)
    {
        $row = $this->db->raw(
            "SELECT COUNT(*) AS reservation_count,
                    SUM(status = 'pending') AS pending_reservations,
                    SUM(status = 'confirmed') AS confirmed_reservations,
                    SUM(status = 'checked_in') AS checked_in_reservations,
                    SUM(status = 'checked_out') AS checked_out_reservations,
                    SUM(status = 'cancelled') AS cancelled_reservations,
                    SUM(status = 'rejected') AS rejected_reservations
             FROM reservations
             WHERE created_at >= ? AND created_at < ?",
            [$from, $toExclusive]
        )->fetch(PDO::FETCH_ASSOC);

        $paymentRow = $this->db->raw(
            "SELECT COALESCE(SUM(amount), 0) AS total
             FROM reservation_payments
             WHERE status = 'paid' AND paid_at >= ? AND paid_at < ?",
            [$from . ' 00:00:00', $toExclusive . ' 00:00:00']
        )->fetch(PDO::FETCH_ASSOC);

        $manualServiceRow = $this->db->raw(
            "SELECT COALESCE(SUM(rs.total_amount), 0) AS total
             FROM reservation_services rs
             WHERE rs.billing_status = 'paid'
               AND rs.billed_at >= ? AND rs.billed_at < ?
               AND NOT EXISTS (
                   SELECT 1
                   FROM reservation_payment_services ps
                   INNER JOIN reservation_payments p ON p.id = ps.payment_id
                   WHERE ps.reservation_service_id = rs.id
                     AND p.kind = 'service' AND p.status = 'paid'
               )",
            [$from . ' 00:00:00', $toExclusive . ' 00:00:00']
        )->fetch(PDO::FETCH_ASSOC);

        $roomNights = $this->db->raw(
            "SELECT COALESCE(SUM(
                        DATEDIFF(LEAST(check_out_date, ?), GREATEST(check_in_date, ?))
                    ), 0) AS total
             FROM reservations
             WHERE status IN ('confirmed', 'checked_in', 'checked_out')
               AND check_in_date < ? AND check_out_date > ?",
            [$toExclusive, $from, $toExclusive, $from]
        )->fetch(PDO::FETCH_ASSOC);

        $rooms = $this->db->raw(
            'SELECT COUNT(*) AS total FROM rooms WHERE is_active = 1'
        )->fetch(PDO::FETCH_ASSOC);

        return [
            'reservations' => array_map('intval', $row ?: []),
            'paid_revenue' => (float) ($paymentRow['total'] ?? 0)
                + (float) ($manualServiceRow['total'] ?? 0),
            'service_revenue' => $this->serviceRevenue($from, $toExclusive),
            'occupied_room_nights' => (int) ($roomNights['total'] ?? 0),
            'active_rooms' => (int) ($rooms['total'] ?? 0),
        ];
    }

    public function reservationStatusBreakdown($from, $toExclusive)
    {
        return $this->db->raw(
            'SELECT status, COUNT(*) AS total
             FROM reservations
             WHERE created_at >= ? AND created_at < ?
             GROUP BY status
             ORDER BY status',
            [$from, $toExclusive]
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function monthlyReservations($from, $toExclusive)
    {
        return $this->db->raw(
            "SELECT DATE_FORMAT(created_at, '%Y-%m') AS month, COUNT(*) AS total
             FROM reservations
             WHERE created_at >= ? AND created_at < ?
             GROUP BY DATE_FORMAT(created_at, '%Y-%m')
             ORDER BY month",
            [$from, $toExclusive]
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function monthlyPayments($from, $toExclusive)
    {
        $payments = $this->db->raw(
            "SELECT DATE_FORMAT(paid_at, '%Y-%m') AS month, SUM(amount) AS total
             FROM reservation_payments
             WHERE status = 'paid' AND paid_at >= ? AND paid_at < ?
             GROUP BY DATE_FORMAT(paid_at, '%Y-%m')",
            [$from . ' 00:00:00', $toExclusive . ' 00:00:00']
        )->fetchAll(PDO::FETCH_ASSOC);

        $manualServices = $this->db->raw(
            "SELECT DATE_FORMAT(rs.billed_at, '%Y-%m') AS month,
                    SUM(rs.total_amount) AS total
             FROM reservation_services rs
             WHERE rs.billing_status = 'paid'
               AND rs.billed_at >= ? AND rs.billed_at < ?
               AND NOT EXISTS (
                   SELECT 1
                   FROM reservation_payment_services ps
                   INNER JOIN reservation_payments p ON p.id = ps.payment_id
                   WHERE ps.reservation_service_id = rs.id
                     AND p.kind = 'service' AND p.status = 'paid'
               )
             GROUP BY DATE_FORMAT(rs.billed_at, '%Y-%m')",
            [$from . ' 00:00:00', $toExclusive . ' 00:00:00']
        )->fetchAll(PDO::FETCH_ASSOC);

        $totals = [];
        foreach (array_merge($payments, $manualServices) as $row) {
            $month = $row['month'];
            $totals[$month] = ($totals[$month] ?? 0) + (float) $row['total'];
        }

        return $totals;
    }

    public function serviceRevenueByName($from, $toExclusive)
    {
        $manual = $this->db->raw(
            "SELECT sc.name, SUM(rs.total_amount) AS amount, COUNT(*) AS requests
             FROM reservation_services rs
             INNER JOIN service_catalog sc ON sc.id = rs.service_id
             WHERE rs.billing_status = 'paid'
               AND rs.billed_at >= ? AND rs.billed_at < ?
               AND NOT EXISTS (
                   SELECT 1
                   FROM reservation_payment_services ps
                   INNER JOIN reservation_payments p ON p.id = ps.payment_id
                   WHERE ps.reservation_service_id = rs.id
                     AND p.kind = 'service' AND p.status = 'paid'
               )
             GROUP BY sc.id, sc.name",
            [$from . ' 00:00:00', $toExclusive . ' 00:00:00']
        )->fetchAll(PDO::FETCH_ASSOC);

        $online = $this->db->raw(
            "SELECT sc.name, SUM(ps.amount) AS amount, COUNT(*) AS requests
             FROM reservation_payment_services ps
             INNER JOIN reservation_payments p ON p.id = ps.payment_id
             INNER JOIN reservation_services rs ON rs.id = ps.reservation_service_id
             INNER JOIN service_catalog sc ON sc.id = rs.service_id
             WHERE p.kind = 'service' AND p.status = 'paid'
               AND p.paid_at >= ? AND p.paid_at < ?
             GROUP BY sc.id, sc.name",
            [$from . ' 00:00:00', $toExclusive . ' 00:00:00']
        )->fetchAll(PDO::FETCH_ASSOC);

        $totals = [];
        foreach (array_merge($manual, $online) as $row) {
            $name = $row['name'];
            if (!isset($totals[$name])) {
                $totals[$name] = ['name' => $name, 'amount' => 0.0, 'requests' => 0];
            }
            $totals[$name]['amount'] += (float) $row['amount'];
            $totals[$name]['requests'] += (int) $row['requests'];
        }

        usort($totals, static fn ($a, $b) => $b['amount'] <=> $a['amount']);

        return array_slice(array_values($totals), 0, 10);
    }

    private function serviceRevenue($from, $toExclusive)
    {
        $row = $this->db->raw(
            "SELECT COALESCE(SUM(p.amount), 0) AS total
             FROM reservation_payments p
             WHERE p.status = 'paid' AND p.kind = 'service'
               AND p.paid_at >= ? AND p.paid_at < ?",
            [$from . ' 00:00:00', $toExclusive . ' 00:00:00']
        )->fetch(PDO::FETCH_ASSOC);

        $manual = $this->db->raw(
            "SELECT COALESCE(SUM(rs.total_amount), 0) AS total
             FROM reservation_services rs
             WHERE rs.billing_status = 'paid'
               AND rs.billed_at >= ? AND rs.billed_at < ?
               AND NOT EXISTS (
                   SELECT 1
                   FROM reservation_payment_services ps
                   INNER JOIN reservation_payments p ON p.id = ps.payment_id
                   WHERE ps.reservation_service_id = rs.id
                     AND p.kind = 'service' AND p.status = 'paid'
               )",
            [$from . ' 00:00:00', $toExclusive . ' 00:00:00']
        )->fetch(PDO::FETCH_ASSOC);

        return (float) ($row['total'] ?? 0) + (float) ($manual['total'] ?? 0);
    }
}
