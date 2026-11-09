<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class CheckinController extends Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->call->model('ReservationsModel', 'reservations');
        $this->call->model('CheckinRecordsModel', 'records');
    }

    private function now()
    {
        return date('Y-m-d H:i:s');
    }

    /** Stays that can be checked in or out today: confirmed arrivals and checked-in stays. */
    public function index()
    {
        $this->api->require_method('GET');

        $rows = $this->reservations->allForAdmin('');
        $today = date('Y-m-d');

        $data = [];
        foreach ($rows as $r) {
            $relevant = in_array($r['status'], ['confirmed', 'checked_in'], true)
                && ($r['status'] === 'checked_in' || $r['check_in_date'] <= $today);

            if (!$relevant) {
                continue;
            }

            $data[] = [
                'id'               => (int) $r['id'],
                'reservation_code' => $r['reservation_code'],
                'guest_name'       => $r['username'],
                'room_number'      => $r['room_number'],
                'category_name'    => $r['category_name'],
                'check_in_date'    => $r['check_in_date'],
                'check_out_date'   => $r['check_out_date'],
                'status'           => $r['status'],
            ];
        }

        $this->api->respond(['success' => true, 'today' => $today, 'data' => $data]);
    }

    public function checkIn($id)
    {
        $this->api->require_method('POST');
        $claims = $this->api->require_jwt();

        $r = $this->reservations->find((int) $id);
        if (!$r) {
            $this->api->respond_error('Reservation not found.', 404);
        }

        if ($r['status'] !== 'confirmed') {
            $this->api->respond_error('Only confirmed reservations can be checked in.', 409);
        }

        if (date('Y-m-d') < $r['check_in_date']) {
            $this->api->respond_error(
                "Check-in is on {$r['check_in_date']}. The guest cannot check in early.",
                409
            );
        }

        $adminId = (int) $claims['sub'];
        $in      = $this->api->body();
        $note    = trim((string) ($in['notes'] ?? '')) ?: null;

        if (!$this->reservations->transition(
            (int) $id, 'confirmed', 'checked_in', $adminId, 'Checked in', []
        )) {
            $this->api->respond_error('Could not check in. Try again.', 500);
        }

        $this->records->insert([
            'reservation_id'  => (int) $id,
            'checked_in_by'   => $adminId,
            'checked_in_at'   => $this->now(),
            'checkin_notes'   => $note,
        ]);

        $this->api->respond(['success' => true, 'message' => 'Guest checked in.']);
    }

    public function checkOut($id)
    {
        $this->api->require_method('POST');
        $claims = $this->api->require_jwt();

        $r = $this->reservations->find((int) $id);
        if (!$r) {
            $this->api->respond_error('Reservation not found.', 404);
        }

        if ($r['status'] !== 'checked_in') {
            $this->api->respond_error('Only checked-in guests can be checked out.', 409);
        }

        $today    = date('Y-m-d');
        $in       = $this->api->body();
        $note     = trim((string) ($in['notes'] ?? '')) ?: null;
        $reason   = trim((string) ($in['early_reason'] ?? ''));
        $planned  = $r['check_out_date'];
        $early    = $today < $planned;

        // The guest cannot leave before the stay has begun.
        if ($today < $r['check_in_date']) {
            $this->api->respond_error('This stay has not started yet.', 409);
        }

        // Leaving before the planned check-out needs an explicit reason.
        if ($early && mb_strlen($reason) < 3) {
            $this->api->respond([
                'success'        => false,
                'message'        => "The planned check-out is {$planned}. Give a reason for the early departure.",
                'errors'         => ["The planned check-out is {$planned}. Give a reason for the early departure."],
                'requires_reason' => true,
            ], 422);
        }

        $adminId = (int) $claims['sub'];

        // An early departure ends the stay today, so the room is released for the unused nights.
        $extra = $early ? ['check_out_date' => $today, 'cancellation_reason' => 'Early departure: ' . $reason] : [];

        if (!$this->reservations->transition(
            (int) $id, 'checked_in', 'checked_out', $adminId,
            $early ? 'Checked out early: ' . $reason : 'Checked out',
            $extra
        )) {
            $this->api->respond_error('Could not check out. Try again.', 500);
        }

        $record = $this->records->forReservation((int) $id);
        $payload = [
            'checked_out_by' => $adminId,
            'checked_out_at' => date('Y-m-d H:i:s'),
            'checkout_notes' => trim(($early ? "Early departure: {$reason}. " : '') . (string) $note) ?: null,
        ];

        if ($record) {
            $this->records->update((int) $record['id'], $payload);
        } else {
            $this->records->insert(array_merge(['reservation_id' => (int) $id], $payload));
        }

        $this->api->respond([
            'success'  => true,
            'message'  => $early
                ? "Guest checked out early on {$today}. The room is released from today."
                : 'Guest checked out. The stay can now be rated.',
            'early'    => $early,
        ]);
    }
}