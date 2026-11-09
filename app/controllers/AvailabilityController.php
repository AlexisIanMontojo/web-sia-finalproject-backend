<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class AvailabilityController extends Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->call->model('ReservationsModel', 'reservations');
        $this->call->model('RoomsModel', 'rooms');
        $this->call->model('RoomBlocksModel', 'blocks');
    }

    private function range()
    {
        $from = (string) ($_GET['from'] ?? date('Y-m-d'));
        $to   = (string) ($_GET['to'] ?? date('Y-m-d', strtotime('+7 days')));

        $errors = [];
        if (!ReservationsModel::isDate($from) || !ReservationsModel::isDate($to)) {
            $errors[] = 'Choose a valid start and end date.';
        } elseif ($to <= $from) {
            $errors[] = 'The end date must be after the start date.';
        } elseif ((strtotime($to) - strtotime($from)) / 86400 > 31) {
            $errors[] = 'Show at most 31 days at a time.';
        }

        if ($errors) {
            $this->api->respond([
                'success' => false,
                'message' => 'Invalid range.',
                'errors'  => $errors,
            ], 422);
        }

        return [$from, $to];
    }

    /**
     * Per room:
     * - bookings: reservations that overlap the range (pending, confirmed, checked in)
     * - blocks: admin blocks, either out of order or occupied (manual)
     * - occupied_now: a checked-in reservation or a manual occupied block covers today
     * - blocked: the room status is maintenance, out of service, or inactive
     */
    public function index()
    {
        $this->api->require_method('GET');
        [$from, $to] = $this->range();

        $today = date('Y-m-d');
        $rooms = $this->rooms->allWithCategory();

        $bookingsByRoom = [];
        foreach ($this->reservations->holdingBetween($from, $to) as $b) {
            $bookingsByRoom[(int) $b['room_id']][] = [
                'id'               => (int) $b['id'],
                'reservation_code' => $b['reservation_code'],
                'check_in_date'    => $b['check_in_date'],
                'check_out_date'   => $b['check_out_date'],
                'status'           => $b['status'],
            ];
        }

        $blocksByRoom = [];
        foreach ($this->blocks->overlapping($from, $to) as $b) {
            $blocksByRoom[(int) $b['room_id']][] = [
                'id'         => (int) $b['id'],
                'start_date' => $b['start_date'],
                'end_date'   => $b['end_date'],
                'block_type' => $b['block_type'] ?? 'out_of_order',
                'reason'     => $b['reason'],
            ];
        }

        $data = array_map(function ($r) use ($bookingsByRoom, $blocksByRoom, $today) {
            $id       = (int) $r['id'];
            $bookings = $bookingsByRoom[$id] ?? [];
            $blocks   = $blocksByRoom[$id] ?? [];

            $roomStatusBlocked = in_array($r['status'], ['maintenance', 'out_of_service'], true)
                || !(int) $r['is_active'];

            $occupiedNow = false;

            foreach ($bookings as $b) {
                if ($b['status'] === 'checked_in'
                    && $b['check_in_date'] <= $today
                    && $b['check_out_date'] > $today) {
                    $occupiedNow = true;
                    break;
                }
            }

            if (!$occupiedNow) {
                foreach ($blocks as $b) {
                    if ($b['block_type'] === 'occupied'
                        && $b['start_date'] <= $today
                        && $b['end_date'] > $today) {
                        $occupiedNow = true;
                        break;
                    }
                }
            }

            return [
                'id'            => $id,
                'room_number'   => $r['room_number'],
                'category_name' => $r['category_name'],
                'capacity'      => (int) $r['capacity'],
                'status'        => $r['status'],
                'is_active'     => (bool) $r['is_active'],
                'blocked'       => $roomStatusBlocked,
                'occupied_now'  => $occupiedNow,
                'bookings'      => $bookings,
                'blocks'        => $blocks,
                'is_free'       => !$roomStatusBlocked && empty($bookings) && empty($blocks),
            ];
        }, $rooms);

        $outOfOrder = function ($r) {
            return $r['blocked'] || count(array_filter(
                $r['blocks'],
                fn ($b) => $b['block_type'] === 'out_of_order'
            )) > 0;
        };

        $this->api->respond([
            'success' => true,
            'range'   => ['from' => $from, 'to' => $to],
            'today'   => $today,
            'summary' => [
                'total'        => count($data),
                'free'         => count(array_filter($data, fn ($r) => $r['is_free'])),
                'booked'       => count(array_filter($data, fn ($r) => !$r['blocked'] && !empty($r['bookings']))),
                'occupied_now' => count(array_filter($data, fn ($r) => $r['occupied_now'])),
                'out_of_order' => count(array_filter($data, $outOfOrder)),
                'with_blocks'  => count(array_filter($data, fn ($r) => !empty($r['blocks']))),
            ],
            'data' => $data,
        ]);
    }
}