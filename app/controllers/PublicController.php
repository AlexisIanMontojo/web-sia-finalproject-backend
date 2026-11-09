<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class PublicController extends Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->call->model('ReservationsModel', 'reservations');
        $this->call->model('RoomCategoriesModel', 'categories');
        $this->call->model('RoomPricingModel', 'pricing');
        $this->call->model('HotelInfoModel', 'hotel');
    }

    private function searchParams()
    {
        $checkIn  = (string) ($_GET['check_in'] ?? '');
        $checkOut = (string) ($_GET['check_out'] ?? '');
        $guests   = (int) ($_GET['guests'] ?? 1);

        $errors = [];
        if (!ReservationsModel::isDate($checkIn) || !ReservationsModel::isDate($checkOut)) {
            $errors[] = 'Choose a valid check-in and check-out date.';
        } elseif ($checkIn < date('Y-m-d')) {
            $errors[] = 'Check-in cannot be in the past.';
        } elseif ($checkOut <= $checkIn) {
            $errors[] = 'Check-out must be after check-in.';
        }

        if ($guests < 1 || $guests > 20) {
            $errors[] = 'Guests must be from 1 to 20.';
        }

        if ($errors) {
            $this->api->respond([
                'success' => false,
                'message' => 'Please correct the search.',
                'errors'  => $errors,
            ], 422);
        }

        return ['check_in' => $checkIn, 'check_out' => $checkOut, 'guests' => $guests];
    }

    public function hotel()
    {
        $this->api->require_method('GET');

        $info = $this->hotel->current();

        if (!$info) {
            $this->api->respond_error('Hotel information is not set up yet.', 404);
        }

        $this->api->respond([
            'success' => true,
            'hotel'   => [
                'name'           => $info['name'],
                'address'        => $info['address'],
                'phone'          => $info['phone'],
                'email'          => $info['email'],
                'check_in_time'  => $info['check_in_time'],
                'check_out_time' => $info['check_out_time'],
                'amenities'      => $info['amenities'],
                'policies'       => $info['policies'],
            ],
        ]);
    }

    public function search()
    {
        $this->api->require_method('GET');
        $p = $this->searchParams();

        $data = [];
        foreach ($this->categories->allActive() as $c) {
            $data[] = [
                'id'              => (int) $c['id'],
                'name'            => $c['name'],
                'description'     => $c['description'],
                'max_occupancy'   => (int) $c['max_occupancy'],
                'amenities'       => $c['amenities'] ? (json_decode($c['amenities'], true) ?: []) : [],
                'available_rooms' => count($this->reservations->availableRooms(
                    (int) $c['id'], $p['check_in'], $p['check_out'], $p['guests']
                )),
                'quote'           => $this->pricing->quote(
                    (int) $c['id'], $p['check_in'], $p['check_out'], $p['guests'], (int) $c['max_occupancy']
                ),
            ];
        }

        $this->api->respond(['success' => true, 'search' => $p, 'data' => $data]);
    }

    public function availability()
    {
        $this->api->require_method('GET');
        $p = $this->searchParams();

        $categoryId = (int) ($_GET['category_id'] ?? 0);
        $category   = $this->categories->find($categoryId);

        if (!$category || !(int) $category['is_active']) {
            $this->api->respond_error('Choose an active room type.', 422);
        }

        $rooms = $this->reservations->availableRooms($categoryId, $p['check_in'], $p['check_out'], $p['guests']);

        $data = array_map(fn ($r) => [
            'id'           => (int) $r['id'],
            'room_number'  => $r['room_number'],
            'floor_number' => $r['floor_number'] === null ? null : (int) $r['floor_number'],
            'capacity'     => (int) $r['capacity'],
            'image_url'    => $r['image_url'],
            'quote'        => $this->pricing->quote(
                $categoryId, $p['check_in'], $p['check_out'], $p['guests'], (int) $r['capacity']
            ),
        ], $rooms);

        $this->api->respond([
            'success'  => true,
            'category' => [
                'id'          => (int) $category['id'],
                'name'        => $category['name'],
                'description' => $category['description'],
            ],
            'data' => $data,
        ]);
    }
}