<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class AdminReportsController extends Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->call->model('AdminReportsModel', 'reports');
    }

    private function validDate($value)
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return false;
        }

        [$year, $month, $day] = array_map('intval', explode('-', $value));
        return checkdate($month, $day, $year);
    }

    private function monthLabels($from, $to)
    {
        $labels = [];
        $month = new DateTimeImmutable(substr($from, 0, 7) . '-01');
        $last = new DateTimeImmutable(substr($to, 0, 7) . '-01');

        while ($month <= $last) {
            $labels[] = $month->format('Y-m');
            $month = $month->modify('+1 month');
        }

        return $labels;
    }

    public function index()
    {
        $this->api->require_method('GET');

        $from = (string) ($_GET['from'] ?? date('Y-m-01'));
        $to = (string) ($_GET['to'] ?? date('Y-m-d'));
        if (!$this->validDate($from) || !$this->validDate($to) || $from > $to) {
            $this->api->respond_error('Choose a valid date range.', 422);
        }

        $endExclusive = (new DateTimeImmutable($to))->modify('+1 day')->format('Y-m-d');
        $days = (int) (new DateTimeImmutable($from))->diff(
            new DateTimeImmutable($endExclusive)
        )->days;
        if ($days > 1826) {
            $this->api->respond_error('Reports can cover up to five years at a time.', 422);
        }

        $overview = $this->reports->overview($from, $endExclusive);
        $capacity = $overview['active_rooms'] * $days;
        $reservationCounts = $overview['reservations'];
        $monthlyReservations = [];
        foreach ($this->reports->monthlyReservations($from, $endExclusive) as $row) {
            $monthlyReservations[$row['month']] = (int) $row['total'];
        }

        $monthlyPayments = $this->reports->monthlyPayments($from, $endExclusive);
        $months = $this->monthLabels($from, $to);
        $reservationTrend = [];
        $revenueTrend = [];
        foreach ($months as $month) {
            $reservationTrend[] = $monthlyReservations[$month] ?? 0;
            $revenueTrend[] = round($monthlyPayments[$month] ?? 0, 2);
        }

        $this->api->respond([
            'success' => true,
            'filters' => ['from' => $from, 'to' => $to],
            'summary' => [
                'reservation_count' => (int) ($reservationCounts['reservation_count'] ?? 0),
                'pending_reservations' => (int) ($reservationCounts['pending_reservations'] ?? 0),
                'confirmed_reservations' => (int) ($reservationCounts['confirmed_reservations'] ?? 0),
                'checked_in_reservations' => (int) ($reservationCounts['checked_in_reservations'] ?? 0),
                'checked_out_reservations' => (int) ($reservationCounts['checked_out_reservations'] ?? 0),
                'cancelled_reservations' => (int) ($reservationCounts['cancelled_reservations'] ?? 0),
                'rejected_reservations' => (int) ($reservationCounts['rejected_reservations'] ?? 0),
                'paid_revenue' => round($overview['paid_revenue'], 2),
                'service_revenue' => round($overview['service_revenue'], 2),
                'occupied_room_nights' => $overview['occupied_room_nights'],
                'occupancy_percent' => $capacity > 0
                    ? round($overview['occupied_room_nights'] / $capacity * 100, 2)
                    : 0,
            ],
            'reservation_statuses' => array_map(
                static fn ($row) => [
                    'status' => $row['status'],
                    'total' => (int) $row['total'],
                ],
                $this->reports->reservationStatusBreakdown($from, $endExclusive)
            ),
            'monthly' => [
                'labels' => $months,
                'reservations' => $reservationTrend,
                'revenue' => $revenueTrend,
            ],
            'top_services' => $this->reports->serviceRevenueByName($from, $endExclusive),
        ]);
    }
}
