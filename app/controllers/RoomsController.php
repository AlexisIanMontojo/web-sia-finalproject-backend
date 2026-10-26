<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class RoomsController extends Controller
{
    const STATUSES = ['available', 'occupied', 'maintenance', 'out_of_service'];

    public function __construct()
    {
        parent::__construct();
        $this->call->model('RoomsModel', 'rooms');
        $this->call->model('RoomCategoriesModel', 'categories');
    }

    private function shape(array $r)
    {
        return [
            'id'            => (int) $r['id'],
            'room_number'   => $r['room_number'],
            'category_id'   => (int) $r['category_id'],
            'category_name' => $r['category_name'] ?? null,
            'description'   => $r['description'],
            'floor_number'  => $r['floor_number'] === null ? null : (int) $r['floor_number'],
            'capacity'      => (int) $r['capacity'],
            'status'        => $r['status'],
            'image_url'     => $r['image_url'],
            'is_active'     => (bool) $r['is_active'],
        ];
    }

    private function validateInput(array $in, $ignoreId = 0)
    {
        $in['status']    = $in['status'] ?? 'available';
        $in['is_active'] = $in['is_active'] ?? true;

        $_POST = $in;
        $this->call->library('form_validation');

        $isValid = $this->form_validation->validate([
            'room_number|Room number' => 'required|max_length[20]',
            'category_id|Category'    => 'required|numeric',
            'capacity|Capacity'       => 'required|numeric|greater_than_equal_to[1]|less_than_equal_to[20]',
            'status|Status'           => 'required|in_list[available,occupied,maintenance,out_of_service]',
        ]);

        $errors = $isValid ? [] : $this->form_validation->get_errors();

        $number = trim((string) ($in['room_number'] ?? ''));
        if ($number !== '' && $this->rooms->numberTaken($number, $ignoreId)) {
            $errors[] = 'This room number is already in use.';
        }

        $categoryId = (int) ($in['category_id'] ?? 0);
        $category   = $categoryId > 0 ? $this->categories->find($categoryId) : null;
        if (!$category || !(int) $category['is_active']) {
            $errors[] = 'Choose an active room category.';
        }

        $floor = trim((string) ($in['floor_number'] ?? ''));
        if ($floor !== '' && !preg_match('/^-?\d{1,3}$/', $floor)) {
            $errors[] = 'Floor must be a whole number.';
        }

        $image = trim((string) ($in['image_url'] ?? ''));
        if ($image !== '' && !filter_var($image, FILTER_VALIDATE_URL)) {
            $errors[] = 'Enter a valid web address.';
        }

        if ($errors) {
            $this->api->respond([
                'success' => false,
                'message' => 'Please correct the errors below.',
                'errors'  => $errors,
            ], 422);
        }

        return [
            'room_number'  => $number,
            'category_id'  => $categoryId,
            'description'  => trim((string) ($in['description'] ?? '')) ?: null,
            'floor_number' => $floor === '' ? null : (int) $floor,
            'capacity'     => (int) $in['capacity'],
            'status'       => $in['status'],
            'image_url'    => $image ?: null,
            'is_active'    => filter_var($in['is_active'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0,
        ];
    }

    public function index()
    {
        $this->api->require_method('GET');

        $this->api->respond([
            'success' => true,
            'data'    => array_map([$this, 'shape'], $this->rooms->allWithCategory()),
        ]);
    }

    public function store()
    {
        $this->api->require_method('POST');

        $id = $this->rooms->insert($this->validateInput($this->api->body()));

        $this->api->respond([
            'success' => true,
            'message' => 'Room created.',
            'data'    => $this->shape($this->rooms->findWithCategory($id)),
        ], 201);
    }

    public function update($id)
    {
        $this->api->require_method('PUT');

        if (!$this->rooms->find((int) $id)) {
            $this->api->respond_error('Room not found.', 404);
        }

        $this->rooms->update((int) $id, $this->validateInput($this->api->body(), (int) $id));

        $this->api->respond([
            'success' => true,
            'message' => 'Room updated.',
            'data'    => $this->shape($this->rooms->findWithCategory($id)),
        ]);
    }

    public function destroy($id)
    {
        $this->api->require_method('DELETE');

        if (!$this->rooms->find((int) $id)) {
            $this->api->respond_error('Room not found.', 404);
        }

        // reservations.room_id is ON DELETE RESTRICT, so check first.
        $bookings = $this->rooms->reservationCount($id);
        if ($bookings > 0) {
            $this->api->respond_error(
                "This room has {$bookings} reservation(s). Set it to inactive instead of deleting it.",
                409
            );
        }

        $this->rooms->delete((int) $id);

        $this->api->respond(['success' => true, 'message' => 'Room deleted.']);
    }
}