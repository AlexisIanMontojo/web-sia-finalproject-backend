<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class DashboardController extends Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->call->model('RoomsModel', 'rooms');
        $this->call->model('UsersModel', 'usersModel');
        $this->call->model('AdminReportsModel', 'reports');
    }

    public function stats()
    {
        $this->api->require_method('GET');

        $statusCounts = [];
        $totalRooms = 0;
        foreach ($this->rooms->dashboardStatusCounts() as $row) {
            $statusCounts[$row['status']] = (int) $row['active_total'];
            $totalRooms += (int) $row['active_total'];
        }

        $byStatus = [];
        foreach (['available', 'occupied', 'maintenance', 'out_of_service'] as $status) {
            $byStatus[] = [
                'status' => $status,
                'total'  => $statusCounts[$status] ?? 0,
            ];
        }
        $byCategory = array_map(
            static fn ($row) => [
                'name'  => $row['name'],
                'total' => (int) $row['total'],
            ],
            $this->rooms->dashboardCategoryCounts()
        );
        $overview = $this->reports->dashboardOverview();
        $reservationCounts = $overview['reservations'];
        $monthStart = (new DateTimeImmutable('first day of this month'))->modify('-11 months');
        $from = $monthStart->format('Y-m-d');
        $toExclusive = (new DateTimeImmutable('tomorrow'))->format('Y-m-d');

        $monthlyReservations = [];
        foreach ($this->reports->monthlyReservations($from, $toExclusive) as $row) {
            $monthlyReservations[$row['month']] = (int) $row['total'];
        }
        $monthlyRevenue = $this->reports->monthlyPayments($from, $toExclusive);
        $trendLabels = [];
        $reservationTrend = [];
        $revenueTrend = [];
        for ($offset = 0; $offset < 12; $offset++) {
            $month = $monthStart->modify("+{$offset} months");
            $key = $month->format('Y-m');
            $trendLabels[] = $month->format('M y');
            $reservationTrend[] = $monthlyReservations[$key] ?? 0;
            $revenueTrend[] = round($monthlyRevenue[$key] ?? 0, 2);
        }

        $this->api->respond([
            'success' => true,
            'stats' => [
                'total_rooms'          => $totalRooms,
                'available_rooms'      => $statusCounts['available'] ?? 0,
                'occupied_rooms'       => $statusCounts['occupied'] ?? 0,
                'maintenance_rooms'    => $statusCounts['maintenance'] ?? 0,
                'room_categories'      => count($byCategory),
                'registered_customers' => $this->usersModel->countCustomers(),
                'total_reservations'   => $reservationCounts['total_reservations'] ?? 0,
                'pending_reservations' => $reservationCounts['pending_reservations'] ?? 0,
                'confirmed_reservations' => $reservationCounts['confirmed_reservations'] ?? 0,
                'checked_in_reservations' => $reservationCounts['checked_in_reservations'] ?? 0,
                'checked_out_reservations' => $reservationCounts['checked_out_reservations'] ?? 0,
                'cancelled_reservations' => $reservationCounts['cancelled_reservations'] ?? 0,
                'rejected_reservations' => $reservationCounts['rejected_reservations'] ?? 0,
                'revenue'              => $overview['revenue'],
            ],
            'rooms_by_status'   => $byStatus,
            'rooms_by_category' => $byCategory,
            'trends' => [
                'labels' => $trendLabels,
                'reservations' => $reservationTrend,
                'revenue' => $revenueTrend,
            ],
            'recent_reservations' => $this->reports->recentReservations(),
            'recent_payments' => $this->reports->recentPayments(),
        ]);
    }
}