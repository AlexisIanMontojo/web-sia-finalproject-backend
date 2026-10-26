<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class DashboardController extends Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->call->model('RoomsModel', 'rooms');
        $this->call->model('RoomCategoriesModel', 'categories');
        $this->call->model('UsersModel', 'usersModel');
    }

    public function stats()
    {
        $this->api->require_method('GET');

        $byStatus = [];
        foreach (['available', 'occupied', 'maintenance', 'out_of_service'] as $status) {
            $byStatus[] = [
                'status' => $status,
                'total'  => $this->rooms->countByStatus($status),
            ];
        }

        $byCategory = [];
        foreach ($this->categories->allActive() as $c) {
            $byCategory[] = [
                'name'  => $c['name'],
                'total' => $this->rooms->countByCategory($c['id']),
            ];
        }

        $this->api->respond([
            'success' => true,
            'stats' => [
                'total_rooms'          => $this->rooms->countActive(),
                'available_rooms'      => $this->rooms->countByStatus('available'),
                'occupied_rooms'       => $this->rooms->countByStatus('occupied'),
                'maintenance_rooms'    => $this->rooms->countByStatus('maintenance'),
                'room_categories'      => $this->categories->countActive(),
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