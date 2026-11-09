<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class TestimonialsController extends Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->call->model('ReviewsModel', 'reviews');
    }

    /** Shows the first letter of the username only, for privacy. */
    private function maskName($username)
    {
        return mb_substr((string) $username, 0, 1) . '***';
    }

    public function index()
    {
        $this->api->require_method('GET');

        $rows = $this->reviews->publicList(12);

        $data = array_map(fn ($r) => [
            'id'       => (int) $r['id'],
            'rating'   => (int) $r['rating'],
            'comment'  => $r['comment'],
            'guest'    => $this->maskName($r['username']),
            'room'     => $r['room_number'],
            'category' => $r['category_name'],
            'date'     => substr($r['created_at'], 0, 10),
        ], $rows);

        $this->api->respond([
            'success' => true,
            'summary' => $this->reviews->summary(),
            'data'    => $data,
        ]);
    }
}