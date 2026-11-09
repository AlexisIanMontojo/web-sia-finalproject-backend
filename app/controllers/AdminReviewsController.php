<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class AdminReviewsController extends Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->call->model('ReviewsModel', 'reviews');
    }

    private function shape(array $r)
    {
        return [
            'id'               => (int) $r['id'],
            'reservation_code' => $r['reservation_code'],
            'guest_name'       => $r['username'],
            'guest_email'      => $r['email'],
            'room_number'      => $r['room_number'],
            'category_name'    => $r['category_name'],
            'rating'           => (int) $r['rating'],
            'comment'          => $r['comment'],
            'is_visible'       => (bool) $r['is_visible'],
            'is_featured'      => (bool) $r['is_featured'],
            'created_at'       => $r['created_at'],
        ];
    }

    public function index()
    {
        $this->api->require_method('GET');

        $this->api->respond([
            'success' => true,
            'data'    => array_map([$this, 'shape'], $this->reviews->allForAdmin()),
        ]);
    }

    /** Show or hide a review, and feature it on the public page. */
    public function update($id)
    {
        $this->api->require_method('PUT');

        if (!$this->reviews->find((int) $id)) {
            $this->api->respond_error('Review not found.', 404);
        }

        $in = $this->api->body();

        $this->reviews->update((int) $id, [
            'is_visible'  => filter_var($in['is_visible'] ?? true, FILTER_VALIDATE_BOOLEAN) ? 1 : 0,
            'is_featured' => filter_var($in['is_featured'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 1 : 0,
        ]);

        $this->api->respond(['success' => true, 'message' => 'Review updated.']);
    }
}