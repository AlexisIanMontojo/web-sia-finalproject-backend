<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class ReservationsController extends Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->call->model('ReservationsModel', 'reservations');
        $this->call->model('RoomsModel', 'rooms');
        $this->call->model('RoomCategoriesModel', 'categories');
        $this->call->model('RoomPricingModel', 'pricing');
    }

    private function currentUserId()
    {
        $claims = $this->api->require_jwt();
        return (int) $claims['sub'];
    }

    private function keyById(array $rows)
    {
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['id']] = $row;
        }
        return $out;
    }

    private function format(array $r, ?array $room, ?array $category)
    {
        return [
            'id'                  => (int) $r['id'],
            'reservation_code'    => $r['reservation_code'],
            'room_number'         => $room['room_number'] ?? null,
            'category_name'       => $category['name'] ?? null,
            'check_in_date'       => $r['check_in_date'],
            'check_out_date'      => $r['check_out_date'],
            'guests'              => (int) $r['adults'],
            'nightly_rate'        => (float) $r['nightly_rate'],
            'extra_charges'       => (float) $r['extra_charges'],
            'total_amount'        => (float) $r['total_amount'],
            'status'              => $r['status'],
            'cancellation_reason' => $r['cancellation_reason'] ?? null,
            'cancellable'         => ReservationsModel::isCancellable($r),
            'is_current'          => $r['check_out_date'] >= date('Y-m-d')
                && !in_array($r['status'], ['cancelled', 'rejected', 'checked_out'], true),
        ];
    }

    public function index()
    {
        $this->api->require_method('GET');
        $reservations = $this->reservations->forUser($this->currentUserId());

        $roomIds = array_values(array_unique(array_map(fn ($r) => (int) $r['room_id'], $reservations)));
        $rooms   = $this->keyById($roomIds ? $this->rooms->findMany($roomIds) : []);

        $categoryIds = array_values(array_unique(array_map(fn ($r) => (int) $r['category_id'], $rooms)));
        $categories  = $this->keyById($categoryIds ? $this->categories->findMany($categoryIds) : []);

        $data = [];
        foreach ($reservations as $r) {
            $room     = $rooms[(int) $r['room_id']] ?? null;
            $category = $room ? ($categories[(int) $room['category_id']] ?? null) : null;
            $data[]   = $this->format($r, $room, $category);
        }

        $this->api->respond(['success' => true, 'data' => $data]);
    }

    public function store()
    {
        $this->api->require_method('POST');
        $userId = $this->currentUserId();

        $in = $this->api->body();
        $in['guests'] = $in['guests'] ?? 1;

        $_POST = $in;
        $this->call->library('form_validation');

        $valid = $this->form_validation->validate([
            'room_id|Room'             => 'required|numeric',
            'check_in|Check-in date'   => 'required|pattern[date_ymd]',
            'check_out|Check-out date' => 'required|pattern[date_ymd]',
            'guests|Guests'            => 'required|numeric|greater_than[0]',
        ]);
        $errors = $valid ? [] : $this->form_validation->get_errors();

        $checkIn  = (string) ($in['check_in'] ?? '');
        $checkOut = (string) ($in['check_out'] ?? '');
        $guests   = (int) $in['guests'];

        if (!$errors) {
            if ($checkIn < date('Y-m-d')) {
                $errors[] = 'Check-in cannot be in the past.';
            }
            if ($checkOut <= $checkIn) {
                $errors[] = 'Check-out must be after check-in.';
            }
        }

        $room = (!$errors) ? $this->rooms->find((int) $in['room_id']) : null;

        if (!$errors && (!$room || !(int) $room['is_active']
            || in_array($room['status'], ['maintenance', 'out_of_service'], true))) {
            $errors[] = 'This room is not available for booking.';
        }

        if ($room && $guests > (int) $room['capacity']) {
            $errors[] = "This room holds up to {$room['capacity']} guests.";
        }

        $quote = null;
        if (!$errors) {
            $quote = $this->pricing->quote(
                (int) $room['category_id'], $checkIn, $checkOut, $guests, (int) $room['capacity']
            );
            if (!$quote) {
                $errors[] = 'No price is set for this room type on the selected date.';
            }
        }

        if ($errors) {
            $this->api->respond([
                'success' => false,
                'message' => 'Please correct the booking.',
                'errors'  => $errors,
            ], 422);
        }

        $code = 'HR' . date('ymd') . strtoupper(bin2hex(random_bytes(3)));

        $this->reservations->transaction();
        try {
            if (!$this->reservations->roomIsFree((int) $room['id'], $checkIn, $checkOut)) {
                $this->reservations->rollback();
                $this->api->respond([
                    'success' => false,
                    'message' => 'That room was just booked for these dates. Please choose another room.',
                ], 409);
            }

            $id = $this->reservations->insert([
                'reservation_code'  => $code,
                'user_id'           => $userId,
                'room_id'           => (int) $room['id'],
                'check_in_date'     => $checkIn,
                'check_out_date'    => $checkOut,
                'adults'            => $guests,
                'children'          => 0,
                'special_requests'  => trim((string) ($in['special_requests'] ?? '')) ?: null,
                'nightly_rate'      => $quote['nightly_rate'],
                'extra_charges'     => $quote['extra_charges'],
                'total_amount'      => $quote['total'],
                'status'            => 'pending',
            ]);

            $this->reservations->addHistory($id, null, 'pending', $userId, 'Booked online by customer');
            $this->reservations->commit();
        } catch (\Throwable $e) {
            $this->reservations->rollback();
            error_log('Booking failed: ' . get_class($e) . ': ' . $e->getMessage());
            $this->api->respond_error('Unable to save the booking. Please try again.', 500);
        }

        $saved    = $this->reservations->findOwned($id, $userId);
        $category = $this->categories->find((int) $room['category_id']);

        $this->api->respond([
            'success' => true,
            'message' => 'Reservation submitted. Your booking is pending hotel confirmation.',
            'data'    => $this->format($saved, $room, $category),
        ], 201);
    }

    public function cancel($id)
    {
        $this->api->require_method('POST');
        $userId = $this->currentUserId();

        $reservation = $this->reservations->findOwned((int) $id, $userId);
        if (!$reservation) {
            $this->api->respond_error('Reservation not found.', 404);
        }

        if (!ReservationsModel::isCancellable($reservation)) {
            $this->api->respond_error('This reservation can no longer be cancelled.', 409);
        }

        $in     = $this->api->body();
        $reason = trim((string) ($in['reason'] ?? '')) ?: 'Cancelled by customer';

        $this->reservations->transaction();
        try {
            $this->reservations->update((int) $id, [
                'status'              => 'cancelled',
                'cancellation_reason' => $reason,
            ]);
            $this->reservations->addHistory((int) $id, $reservation['status'], 'cancelled', $userId, $reason);
            $this->reservations->commit();
        } catch (\Throwable $e) {
            $this->reservations->rollback();
            error_log('Cancellation failed: ' . get_class($e) . ': ' . $e->getMessage());
            $this->api->respond_error('Unable to cancel the reservation. Please try again.', 500);
        }

        $this->api->respond(['success' => true, 'message' => 'Reservation cancelled.']);
    }
}