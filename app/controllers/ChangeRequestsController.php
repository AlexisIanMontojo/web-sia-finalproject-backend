<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class ChangeRequestsController extends Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->call->model('ChangeRequestsModel', 'changes');
        $this->call->model('ReservationsModel', 'reservations');
        $this->call->model('RoomsModel', 'rooms');
    }

    private function currentUserId()
    {
        $claims = $this->api->require_jwt();
        return (int) $claims['sub'];
    }

    private function shape(array $c)
    {
        return [
            'id'                  => (int) $c['id'],
            'reservation_id'      => (int) $c['reservation_id'],
            'requested_check_in'  => $c['requested_check_in'],
            'requested_check_out' => $c['requested_check_out'],
            'requested_guests'    => (int) $c['requested_guests'],
            'reason'              => $c['reason'],
            'status'              => $c['status'],
            'admin_note'          => $c['admin_note'],
            'created_at'          => $c['created_at'],
        ];
    }

    public function index()
    {
        $this->api->require_method('GET');

        $this->api->respond([
            'success' => true,
            'data'    => array_map([$this, 'shape'], $this->changes->forUser($this->currentUserId())),
        ]);
    }

    public function store($id)
    {
        $this->api->require_method('POST');
        $userId = $this->currentUserId();

        $reservation = $this->reservations->findOwned((int) $id, $userId);
        if (!$reservation) {
            $this->api->respond_error('Reservation not found.', 404);
        }

        if (!ReservationsModel::isCancellable($reservation)) {
            $this->api->respond_error('This reservation can no longer be changed.', 409);
        }

        if ($this->changes->hasPendingFor((int) $id)) {
            $this->api->respond_error('This reservation already has a pending change request.', 409);
        }

        $in = $this->api->body();

        $_POST = $in;
        $this->call->library('form_validation');

        $valid = $this->form_validation->validate([
            'check_in|Check-in date'   => 'required|pattern[date_ymd]',
            'check_out|Check-out date' => 'required|pattern[date_ymd]',
            'guests|Guests'            => 'required|numeric|greater_than[0]',
        ]);
        $errors = $valid ? [] : $this->form_validation->get_errors();

        $checkIn  = (string) ($in['check_in'] ?? '');
        $checkOut = (string) ($in['check_out'] ?? '');
        $guests   = (int) ($in['guests'] ?? 0);

        if (!$errors) {
            if ($checkIn < date('Y-m-d')) {
                $errors[] = 'Check-in cannot be in the past.';
            }
            if ($checkOut <= $checkIn) {
                $errors[] = 'Check-out must be after check-in.';
            }
        }

        $room = $this->rooms->find((int) $reservation['room_id']);

        if (!$errors && $guests > (int) $room['capacity']) {
            $errors[] = "This room holds up to {$room['capacity']} guests.";
        }

        if (!$errors && !$this->reservations->roomIsFree(
            (int) $reservation['room_id'], $checkIn, $checkOut, (int) $id
        )) {
            $errors[] = 'Your room is not free for the requested dates.';
        }

        if ($errors) {
            $this->api->respond([
                'success' => false,
                'message' => 'Please correct the request.',
                'errors'  => $errors,
            ], 422);
        }

        $this->changes->insert([
            'reservation_id'      => (int) $id,
            'user_id'             => $userId,
            'requested_check_in'  => $checkIn,
            'requested_check_out' => $checkOut,
            'requested_guests'    => $guests,
            'reason'              => trim((string) ($in['reason'] ?? '')) ?: null,
            'status'              => 'pending',
        ]);

        $this->api->respond([
            'success' => true,
            'message' => 'Change request sent. The hotel will review it and confirm by email.',
        ], 201);
    }
}