<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class CustomerServicesController extends Controller
{
    const MAX_QUANTITY = 50;
    const MAX_HOURS    = 8;

    const UNIT_LABEL = [
        'per_item'   => 'Pieces',
        'per_person' => 'Guests',
        'per_hour'   => 'Hours',
        'per_stay'   => 'Stay',
    ];

    public function __construct()
    {
        parent::__construct();
        $this->call->model('ServiceCatalogModel', 'services');
        $this->call->model('ReservationServicesModel', 'requests');
        $this->call->model('ReservationsModel', 'reservations');
    }

    private function currentUserId()
    {
        $claims = $this->api->require_jwt();
        return (int) $claims['sub'];
    }

    /** Services can be requested only while the stay is open. */
    private function isOpenStay(array $r)
    {
        return in_array($r['status'], ['pending', 'confirmed', 'checked_in'], true)
            && $r['check_out_date'] >= date('Y-m-d');
    }

    private function shapeService(array $s)
    {
        return [
            'id'                => (int) $s['id'],
            'name'              => $s['name'],
            'description'       => $s['description'],
            'category'          => $s['category'],
            'price'             => (float) $s['price'],
            'unit'              => $s['unit'],
            'unit_label'        => self::UNIT_LABEL[$s['unit']] ?? 'Quantity',
            'requires_schedule' => (bool) $s['requires_schedule'],
            'duration_minutes'  => (int) $s['duration_minutes'],
        ];
    }

    private function shapeRequest(array $r)
    {
        return [
            'id'             => (int) $r['id'],
            'service_id'     => (int) $r['service_id'],
            'name'           => $r['name'] ?? null,
            'category'       => $r['category'] ?? null,
            'unit'           => $r['unit'] ?? null,
            'quantity'       => (int) $r['quantity'],
            'unit_price'     => (float) $r['unit_price'],
            'total_amount'   => (float) $r['total_amount'],
            'scheduled_at'   => $r['scheduled_at'],
            'ends_at'        => $r['ends_at'],
            'notes'          => $r['notes'],
            'status'         => $r['status'],
            'billing_status' => $r['billing_status'],
            'created_at'     => $r['created_at'],
        ];
    }

    /** GET /api/customer/services */
    public function catalog()
    {
        $this->api->require_method('GET');

        $this->api->respond([
            'success' => true,
            'data'    => array_map([$this, 'shapeService'], $this->services->active()),
        ]);
    }

    /** GET /api/customer/reservations/{id}/services */
    public function forReservation($id)
    {
        $this->api->require_method('GET');

        $reservation = $this->reservations->findOwned((int) $id, $this->currentUserId());
        if (!$reservation) {
            $this->api->respond_error('Reservation not found.', 404);
        }

        $rows  = $this->requests->forReservation((int) $id);
        $total = 0.0;

        foreach ($rows as $r) {
            if ($r['status'] !== 'cancelled') {
                $total += (float) $r['total_amount'];
            }
        }

        $this->api->respond([
            'success' => true,
            'open'    => $this->isOpenStay($reservation),
            'total'   => round($total, 2),
            'data'    => array_map([$this, 'shapeRequest'], $rows),
        ]);
    }

    /** POST /api/customer/reservations/{id}/services */
    public function add($id)
    {
        $this->api->require_method('POST');
        $userId      = $this->currentUserId();
        $reservation = $this->reservations->findOwned((int) $id, $userId);

        if (!$reservation) {
            $this->api->respond_error('Reservation not found.', 404);
        }

        if (!$this->isOpenStay($reservation)) {
            $this->api->respond_error('This stay is no longer open for extra services.', 409);
        }

        $in        = $this->api->body();
        $serviceId = (int) ($in['service_id'] ?? 0);
        $quantity  = (int) ($in['quantity'] ?? 1);
        $when      = trim((string) ($in['scheduled_at'] ?? ''));
        $notes     = trim((string) ($in['notes'] ?? '')) ?: null;

        $service = $serviceId > 0 ? $this->services->findActive($serviceId) : null;
        $errors  = [];

        if (!$service) {
            $errors[] = 'Choose an available service.';
        } else {
            if ($service['unit'] === 'per_stay') {
                $quantity = 1;
            }

            if ($quantity < 1 || $quantity > self::MAX_QUANTITY) {
                $errors[] = 'Quantity must be from 1 to ' . self::MAX_QUANTITY . '.';
            }

            if ($service['unit'] === 'per_hour' && $quantity > self::MAX_HOURS) {
                $errors[] = 'Book up to ' . self::MAX_HOURS . ' hours at a time.';
            }
        }

        $start = null;

        if ($service && (bool) $service['requires_schedule'] && !$errors) {
            $start = DateTime::createFromFormat('Y-m-d\TH:i', $when);

            if (!$start) {
                $errors[] = 'Choose a date and time for this service.';
            } elseif ($start <= new DateTime()) {
                $errors[] = 'Choose a time in the future.';
            } else {
                $date = $start->format('Y-m-d');
                if ($date < $reservation['check_in_date'] || $date > $reservation['check_out_date']) {
                    $errors[] = "Choose a date during your stay ({$reservation['check_in_date']} to {$reservation['check_out_date']}).";
                }
            }
        }

        if ($errors) {
            $this->api->respond([
                'success' => false,
                'message' => 'Please correct the request.',
                'errors'  => $errors,
            ], 422);
        }

        $startStr = null;
        $endStr   = null;

        if ($start) {
            $minutes  = $service['unit'] === 'per_hour' ? $quantity * 60 : (int) $service['duration_minutes'];
            $startStr = $start->format('Y-m-d H:i:s');
            $endStr   = (clone $start)->modify("+{$minutes} minutes")->format('Y-m-d H:i:s');

            if ($this->requests->bookedCount((int) $service['id'], $startStr, $endStr) >= (int) $service['capacity']) {
                $this->api->respond_error('That time is fully booked. Please choose another time.', 409);
            }
        }

        $unitPrice = (float) $service['price'];

        $newId = $this->requests->insert([
            'reservation_id' => (int) $reservation['id'],
            'service_id'     => (int) $service['id'],
            'quantity'       => $quantity,
            'unit_price'     => $unitPrice,
            'total_amount'   => round($unitPrice * $quantity, 2),
            'scheduled_at'   => $startStr,
            'ends_at'        => $endStr,
            'notes'          => $notes,
            'status'         => 'requested',
            'billing_status' => 'unpaid',
            'billed_at'      => null,
        ]);

        $row             = $this->requests->findOwned((int) $newId, $userId);
        $row['name']     = $service['name'];
        $row['category'] = $service['category'];
        $row['unit']     = $service['unit'];

        $this->api->respond([
            'success' => true,
            'message' => 'Request sent. The hotel will confirm it, and the charge is added to your bill.',
            'data'    => $this->shapeRequest($row),
        ], 201);
    }

    /** POST /api/customer/reservation-services/{id}/cancel */
    public function cancel($id)
    {
        $this->api->require_method('POST');

        $row = $this->requests->findOwned((int) $id, $this->currentUserId());
        if (!$row) {
            $this->api->respond_error('Request not found.', 404);
        }

        if ($row['status'] !== 'requested') {
            $this->api->respond_error('The hotel has already confirmed this. Contact the hotel to cancel it.', 409);
        }

        if ($row['billing_status'] === 'paid') {
            $this->api->respond_error('This service is already paid and cannot be cancelled here.', 409);
        }

        $this->requests->update((int) $id, ['status' => 'cancelled']);

        $this->api->respond(['success' => true, 'message' => 'Request cancelled.']);
    }
}