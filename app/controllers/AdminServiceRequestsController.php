<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class AdminServiceRequestsController extends Controller
{
    const FILTERS = ['requested', 'confirmed', 'completed', 'cancelled', 'all'];

    public function __construct()
    {
        parent::__construct();
        $this->call->model('ReservationServicesModel', 'requests');
    }

    private function shape(array $r)
    {
        return [
            'id'               => (int) $r['id'],
            'reservation_code' => $r['reservation_code'],
            'guest_name'       => $r['username'],
            'room_number'      => $r['room_number'],
            'stay'             => $r['check_in_date'] . ' to ' . $r['check_out_date'],
            'name'             => $r['name'],
            'category'         => $r['category'],
            'unit'             => $r['unit'],
            'quantity'         => (int) $r['quantity'],
            'total_amount'     => (float) $r['total_amount'],
            'scheduled_at'     => $r['scheduled_at'],
            'ends_at'          => $r['ends_at'],
            'notes'            => $r['notes'],
            'status'           => $r['status'],
            'billing_status'   => $r['billing_status'],
        ];
    }

    private function findOr404($id)
    {
        $row = $this->requests->find((int) $id);

        if (!$row) {
            $this->api->respond_error('Request not found.', 404);
        }

        return $row;
    }

    private function moveStatus($id, $from, $to, $message)
    {
        $row = $this->findOr404($id);

        if ($row['status'] !== $from) {
            $this->api->respond_error("Only {$from} requests can be marked {$to}.", 409);
        }

        $this->requests->update((int) $id, ['status' => $to]);

        $this->api->respond(['success' => true, 'message' => $message]);
    }

    /** GET /api/admin/service-requests?status=requested */
    public function index()
    {
        $this->api->require_method('GET');

        $status = (string) ($_GET['status'] ?? 'requested');
        if (!in_array($status, self::FILTERS, true)) {
            $this->api->respond_error('Unknown filter.', 422);
        }

        $this->api->respond([
            'success' => true,
            'data'    => array_map([$this, 'shape'], $this->requests->adminList($status)),
        ]);
    }

    /** POST /api/admin/service-requests/{id}/confirm */
    public function confirm($id)
    {
        $this->api->require_method('POST');
        $this->moveStatus($id, 'requested', 'confirmed', 'Request confirmed.');
    }

    /** POST /api/admin/service-requests/{id}/complete */
    public function complete($id)
    {
        $this->api->require_method('POST');
        $this->moveStatus($id, 'confirmed', 'completed', 'Service marked completed.');
    }

    /** POST /api/admin/service-requests/{id}/billed (manager only) */
    public function markBilled($id)
    {
        $this->api->require_method('POST');
        $row = $this->findOr404($id);

        if ($row['status'] !== 'completed') {
            $this->api->respond_error('Only completed services can be billed.', 409);
        }

        if ($row['billing_status'] === 'paid') {
            $this->api->respond_error('This service is already marked paid.', 409);
        }

        $this->requests->update((int) $id, [
            'billing_status' => 'paid',
            'billed_at'      => date('Y-m-d H:i:s'),
        ]);

        $this->api->respond(['success' => true, 'message' => 'Marked as paid.']);
    }
}