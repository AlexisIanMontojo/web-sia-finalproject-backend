<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class RoomBlocksController extends Controller
{
    const TYPES = ['out_of_order', 'occupied'];

    public function __construct()
    {
        parent::__construct();
        $this->call->model('RoomBlocksModel', 'blocks');
        $this->call->model('ReservationsModel', 'reservations');
        $this->call->model('RoomsModel', 'rooms');
    }

    private function range()
    {
        $from = (string) ($_GET['from'] ?? date('Y-m-d'));
        $to   = (string) ($_GET['to'] ?? date('Y-m-d', strtotime('+31 days')));

        if (!ReservationsModel::isDate($from) || !ReservationsModel::isDate($to) || $to <= $from) {
            $this->api->respond_error('Choose a valid date range.', 422);
        }

        return [$from, $to];
    }

    private function shape(array $b, array $room)
    {
        return [
            'id'          => (int) $b['id'],
            'room_id'     => (int) $b['room_id'],
            'room_number' => $room['room_number'],
            'start_date'  => $b['start_date'],
            'end_date'    => $b['end_date'],
            'block_type'  => $b['block_type'] ?? 'out_of_order',
            'reason'      => $b['reason'],
            'created_at'  => $b['created_at'],
        ];
    }

    private function validateInput(array $in)
    {
        $roomId = (int) ($in['room_id'] ?? 0);
        $from   = (string) ($in['start_date'] ?? '');
        $to     = (string) ($in['end_date'] ?? '');
        $type   = (string) ($in['block_type'] ?? 'out_of_order');
        $reason = trim((string) ($in['reason'] ?? '')) ?: null;

        $errors = [];

        $room = $roomId > 0 ? $this->rooms->find($roomId) : null;
        if (!$room) {
            $errors[] = 'Choose a valid room.';
        }

        if (!in_array($type, self::TYPES, true)) {
            $errors[] = 'Choose a valid block type.';
        }

        if (!ReservationsModel::isDate($from) || !ReservationsModel::isDate($to)) {
            $errors[] = 'Choose a valid start and end date.';
        } elseif ($to <= $from) {
            $errors[] = 'The end date must be after the start date. It is the first night the room is open again.';
        }

        if ($reason !== null && mb_strlen($reason) > 255) {
            $errors[] = 'The reason must be 255 characters or fewer.';
        }

        if ($errors) {
            $this->api->respond([
                'success' => false,
                'message' => 'Please correct the block.',
                'errors'  => $errors,
            ], 422);
        }

        return [
            'room_id'    => $roomId,
            'room'       => $room,
            'start_date' => $from,
            'end_date'   => $to,
            'block_type' => $type,
            'reason'     => $reason,
        ];
    }

    public function index()
    {
        $this->api->require_method('GET');
        [$from, $to] = $this->range();

        $roomsById = [];
        foreach ($this->rooms->allWithCategory() as $r) {
            $roomsById[(int) $r['id']] = $r;
        }

        $data = [];
        foreach ($this->blocks->overlapping($from, $to) as $b) {
            $room = $roomsById[(int) $b['room_id']] ?? null;
            if ($room) {
                $data[] = $this->shape($b, $room);
            }
        }

        $this->api->respond(['success' => true, 'data' => $data]);
    }

    public function store()
    {
        $this->api->require_method('POST');
        $claims = $this->api->require_jwt();

        $in = $this->validateInput($this->api->body());

        if ($this->blocks->hasOverlap($in['room_id'], $in['start_date'], $in['end_date'])) {
            $this->api->respond_error('This room already has a block or occupancy for these dates.', 409);
        }

        $conflicts = $this->reservations->conflictsForRoom(
            $in['room_id'], $in['start_date'], $in['end_date']
        );

        if ($conflicts) {
            $codes = implode(', ', array_column($conflicts, 'reservation_code'));
            $this->api->respond_error(
                "Cannot save these dates. Reservation(s) {$codes} already hold this room. Move or cancel them first.",
                409
            );
        }

        $id = $this->blocks->insert([
            'room_id'    => $in['room_id'],
            'start_date' => $in['start_date'],
            'end_date'   => $in['end_date'],
            'block_type' => $in['block_type'],
            'reason'     => $in['reason'],
            'created_by' => (int) $claims['sub'],
        ]);

        $this->api->respond([
            'success' => true,
            'message' => $in['block_type'] === 'occupied' ? 'Room marked occupied.' : 'Dates blocked.',
            'data'    => $this->shape($this->blocks->find((int) $id), $in['room']),
        ], 201);
    }

    public function destroy($id)
    {
        $this->api->require_method('DELETE');

        if (!$this->blocks->find((int) $id)) {
            $this->api->respond_error('Block not found.', 404);
        }

        $this->blocks->delete((int) $id);

        $this->api->respond(['success' => true, 'message' => 'Removed.']);
    }
}