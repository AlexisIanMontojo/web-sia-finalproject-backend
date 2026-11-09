<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class ReviewsController extends Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->call->model('ReviewsModel', 'reviews');
        $this->call->model('ReservationsModel', 'reservations');
    }

    private function currentUserId()
    {
        $claims = $this->api->require_jwt();
        return (int) $claims['sub'];
    }

    private function shape(array $r)
    {
        return [
            'id'             => (int) $r['id'],
            'reservation_id' => (int) $r['reservation_id'],
            'rating'         => (int) $r['rating'],
            'comment'        => $r['comment'],
            'is_visible'     => (bool) $r['is_visible'],
            'created_at'     => $r['created_at'],
        ];
    }

    /** The customer's own reviews, plus the reservation ids already reviewed. */
    public function mine()
    {
        $this->api->require_method('GET');

        $rows = $this->reviews->forUser($this->currentUserId());

        $this->api->respond([
            'success'                  => true,
            'data'                     => array_map([$this, 'shape'], $rows),
            'reviewed_reservation_ids' => array_map(fn ($r) => (int) $r['reservation_id'], $rows),
        ]);
    }

    public function store()
    {
        $this->api->require_method('POST');
        $userId = $this->currentUserId();
        $in     = $this->api->body();

        $_POST = $in;
        $this->call->library('form_validation');

        $valid = $this->form_validation->validate([
            'reservation_id|Reservation' => 'required|numeric',
            'rating|Rating'              => 'required|numeric|greater_than_equal_to[1]|less_than_equal_to[5]',
            'comment|Comment'            => 'max_length[1000]',
        ]);

        $errors = $valid ? [] : $this->form_validation->get_errors();

        if (!$errors && (int) $in['rating'] != $in['rating']) {
            $errors[] = 'Rating must be a whole number from 1 to 5.';
        }

        if ($errors) {
            $this->api->respond([
                'success' => false,
                'message' => 'Please correct the review.',
                'errors'  => $errors,
            ], 422);
        }

        $reservation = $this->reservations->findOwned((int) $in['reservation_id'], $userId);
        if (!$reservation) {
            $this->api->respond_error('Reservation not found.', 404);
        }

        if ($reservation['status'] !== 'checked_out') {
            $this->api->respond_error('You can rate your stay after check-out.', 409);
        }

        if ($this->reviews->findByReservation((int) $reservation['id'])) {
            $this->api->respond_error('You have already rated this stay.', 409);
        }

        $id = $this->reviews->insert([
            'reservation_id' => (int) $reservation['id'],
            'user_id'        => $userId,
            'room_id'        => (int) $reservation['room_id'],
            'rating'         => (int) $in['rating'],
            'comment'        => trim((string) ($in['comment'] ?? '')) ?: null,
            'is_visible'     => 1,
            'is_featured'    => 0,
        ]);

        $this->api->respond([
            'success' => true,
            'message' => 'Thank you for your review.',
            'data'    => $this->shape($this->reviews->find((int) $id)),
        ], 201);
    }
}