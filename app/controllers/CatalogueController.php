<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class CatalogueController extends Controller
{
    /** Base nightly rate per room type, looked up once per request. */
    private $baseRates = [];

    public function __construct()
    {
        parent::__construct();
        $this->call->model('RoomsModel', 'rooms');
        $this->call->model('RoomCategoriesModel', 'categories');
        $this->call->model('ReservationsModel', 'reservations');
        $this->call->model('RoomPricingModel', 'pricing');
    }

    /** Search options from the query string. Dates are optional; guests defaults to 1. */
    private function search()
    {
        $checkIn    = trim((string) ($_GET['check_in'] ?? ''));
        $checkOut   = trim((string) ($_GET['check_out'] ?? ''));
        $guests     = (int) ($_GET['guests'] ?? 1);
        $categoryId = (int) ($_GET['category_id'] ?? 0);
        $hasDates   = $checkIn !== '' || $checkOut !== '';

        $errors = [];

        if ($hasDates) {
            if (!ReservationsModel::isDate($checkIn) || !ReservationsModel::isDate($checkOut)) {
                $errors[] = 'Choose both a valid check-in and check-out date.';
            } elseif ($checkIn < date('Y-m-d')) {
                $errors[] = 'Check-in cannot be in the past.';
            } elseif ($checkOut <= $checkIn) {
                $errors[] = 'Check-out must be after check-in.';
            } elseif ((strtotime($checkOut) - strtotime($checkIn)) / 86400 > 30) {
                $errors[] = 'A stay can be at most 30 nights.';
            }
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

        return [
            'check_in'    => $hasDates ? $checkIn : null,
            'check_out'   => $hasDates ? $checkOut : null,
            'guests'      => $guests,
            'category_id' => $categoryId,
            'has_dates'   => $hasDates,
        ];
    }

    private function baseRate($categoryId)
    {
        if (!array_key_exists($categoryId, $this->baseRates)) {
            $price = $this->pricing->activeOn($categoryId, date('Y-m-d'));
            $this->baseRates[$categoryId] = $price ? (float) $price['nightly_rate'] : null;
        }

        return $this->baseRates[$categoryId];
    }

    private function unavailableFor(array $s)
    {
        return $s['has_dates']
            ? $this->reservations->unavailableRoomIds($s['check_in'], $s['check_out'])
            : [];
    }

    private function shape(array $r, array $s, array $unavailable)
    {
        $id         = (int) $r['id'];
        $categoryId = (int) $r['category_id'];
        $capacity   = (int) $r['capacity'];
        $bookable   = $r['status'] === 'available';
        $fits       = $s['guests'] <= $capacity;

        $available = null;
        $quote     = null;

        if ($s['has_dates']) {
            $available = $bookable && $fits && !in_array($id, $unavailable, true);
            $quote     = $this->pricing->quote(
                $categoryId,
                $s['check_in'],
                $s['check_out'],
                $s['guests'],
                $capacity
            );
        }

        return [
            'id'                => $id,
            'room_number'       => $r['room_number'],
            'category_id'       => $categoryId,
            'category_name'     => $r['category_name'],
            'description'       => $r['description'] ?: $r['category_description'],
            'amenities'         => $r['category_amenities'] ? (json_decode($r['category_amenities'], true) ?: []) : [],
            'floor_number'      => $r['floor_number'] === null ? null : (int) $r['floor_number'],
            'capacity'          => $capacity,
            'image_url'         => $r['image_url'],
            'status'            => $r['status'],
            'bookable'          => $bookable,
            'fits_guests'       => $fits,
            'base_nightly_rate' => $this->baseRate($categoryId),
            'available'         => $available,
            'quote'             => $quote,
        ];
    }

    /** GET /api/public/rooms: every room, filtered by type and guests, with availability when dates are given. */
    public function index()
    {
        $this->api->require_method('GET');
        $s           = $this->search();
        $unavailable = $this->unavailableFor($s);

        $data = [];
        foreach ($this->rooms->catalogue($s['category_id']) as $r) {
            if ((int) $r['capacity'] < $s['guests']) {
                continue;
            }
            $data[] = $this->shape($r, $s, $unavailable);
        }

        $this->api->respond([
            'success'    => true,
            'search'     => $s,
            'categories' => array_map(
                fn ($c) => ['id' => (int) $c['id'], 'name' => $c['name']],
                $this->categories->allActive()
            ),
            'summary' => [
                'shown'     => count($data),
                'available' => $s['has_dates']
                    ? count(array_filter($data, fn ($r) => $r['available']))
                    : null,
            ],
            'data' => $data,
        ]);
    }

    /** GET /api/public/rooms/{id}: one room, with its quote for the dates when given. */
    public function show($id)
    {
        $this->api->require_method('GET');
        $s = $this->search();

        $room = $this->rooms->catalogueItem((int) $id);
        if (!$room) {
            $this->api->respond_error('Room not found.', 404);
        }

        $this->api->respond([
            'success' => true,
            'search'  => $s,
            'room'    => $this->shape($room, $s, $this->unavailableFor($s)),
        ]);
    }
}