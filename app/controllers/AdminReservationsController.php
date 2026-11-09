<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class AdminReservationsController extends Controller
{
    const FILTERS = ['pending', 'confirmed', 'checked_in', 'checked_out', 'cancelled', 'rejected'];

    public function __construct()
    {
        parent::__construct();
        $this->call->model('ReservationsModel', 'reservations');
    }

    private function shape(array $r)
    {
        return [
            'id'                  => (int) $r['id'],
            'reservation_code'    => $r['reservation_code'],
            'guest_name'          => $r['username'],
            'guest_email'         => $r['email'],
            'room_number'         => $r['room_number'],
            'category_name'       => $r['category_name'],
            'check_in_date'       => $r['check_in_date'],
            'check_out_date'      => $r['check_out_date'],
            'guests'              => (int) $r['adults'],
            'total_amount'        => (float) $r['total_amount'],
            'status'              => $r['status'],
            'cancellation_reason' => $r['cancellation_reason'],
            'created_at'          => $r['created_at'],
        ];
    }

    public function index()
    {
        $this->api->require_method('GET');

        $status = (string) ($_GET['status'] ?? '');
        if ($status !== '' && !in_array($status, self::FILTERS, true)) {
            $this->api->respond_error('Unknown status filter.', 422);
        }

        $this->api->respond([
            'success' => true,
            'data'    => array_map([$this, 'shape'], $this->reservations->allForAdmin($status)),
        ]);
    }

    public function confirm($id)
    {
        $this->api->require_method('POST');
        $claims = $this->api->require_jwt();

        $reservation = $this->reservations->find((int) $id);
        if (!$reservation) {
            $this->api->respond_error('Reservation not found.', 404);
        }

        if ($reservation['status'] !== 'pending') {
            $this->api->respond_error('Only pending reservations can be confirmed.', 409);
        }

        // Re-check the room: a block may have been added since the booking.
        if (!$this->reservations->roomIsFree(
            (int) $reservation['room_id'],
            $reservation['check_in_date'],
            $reservation['check_out_date'],
            (int) $id
        )) {
            $this->api->respond_error('The room is no longer free for these dates.', 409);
        }

        if (!$this->reservations->changeStatus(
            (int) $id, 'pending', 'confirmed', (int) $claims['sub'], 'Confirmed by admin'
        )) {
            $this->api->respond_error('Could not confirm the reservation. Try again.', 500);
        }

        $this->api->respond([
            'success' => true,
            'message' => 'Reservation confirmed.',
            'data'    => $this->shape($this->reservations->allForAdmin('')[0] ?? []),
        ]);
    }

    public function reject($id)
    {
        $this->api->require_method('POST');
        $claims = $this->api->require_jwt();

        $reservation = $this->reservations->find((int) $id);
        if (!$reservation) {
            $this->api->respond_error('Reservation not found.', 404);
        }

        if ($reservation['status'] !== 'pending') {
            $this->api->respond_error('Only pending reservations can be rejected.', 409);
        }

        $in     = $this->api->body();
        $reason = trim((string) ($in['reason'] ?? ''));

        if (mb_strlen($reason) < 3) {
            $this->api->respond([
                'success' => false,
                'message' => 'Please give a reason for rejecting this booking.',
                'errors'  => ['Please give a reason for rejecting this booking.'],
            ], 422);
        }

        if (!$this->reservations->changeStatus(
            (int) $id, 'pending', 'rejected', (int) $claims['sub'], $reason,
            ['cancellation_reason' => $reason]
        )) {
            $this->api->respond_error('Could not reject the reservation. Try again.', 500);
        }

        $this->api->respond(['success' => true, 'message' => 'Reservation rejected. The room is released.']);
    }
}