<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class DashboardController extends Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->call->model('RoomsModel', 'rooms');
        $this->call->model('UsersModel', 'usersModel');
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

        $this->api->respond([
            'success' => true,
            'stats' => [
                'total_rooms'          => $totalRooms,
                'available_rooms'      => $statusCounts['available'] ?? 0,
                'occupied_rooms'       => $statusCounts['occupied'] ?? 0,
                'maintenance_rooms'    => $statusCounts['maintenance'] ?? 0,
                'room_categories'      => count($byCategory),
                'registered_customers' => $this->usersModel->countCustomers(),
                // Reservations and payments are built in a later batch.
                'total_reservations'   => null,
                'pending_reservations' => null,
                'revenue'              => null,
            ],
            'rooms_by_status'   => $byStatus,
            'rooms_by_category' => $byCategory,
        ]);
    }
}