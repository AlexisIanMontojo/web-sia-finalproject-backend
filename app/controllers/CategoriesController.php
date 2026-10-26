<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class CategoriesController extends Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->call->model('RoomCategoriesModel', 'categories');
        $this->call->model('RoomsModel', 'rooms');
    }

    private function shape(array $c)
    {
        return [
            'id'            => (int) $c['id'],
            'name'          => $c['name'],
            'description'   => $c['description'],
            'max_occupancy' => (int) $c['max_occupancy'],
            'amenities'     => $c['amenities'] ? (json_decode($c['amenities'], true) ?: []) : [],
            'is_active'     => (bool) $c['is_active'],
            'room_count'    => $this->rooms->countByCategory($c['id']),
        ];
    }

    private function validateInput(array $in, $ignoreId = 0)
    {
        // form_validation reads $_POST, so the decoded JSON body is copied in.
        $_POST = $in;
        $this->call->library('form_validation');

        $isValid = $this->form_validation->validate([
            'name|Name'                       => 'required|max_length[100]',
            'max_occupancy|Maximum occupancy' => 'required|numeric|greater_than_equal_to[1]',
        ]);

        $errors = $isValid ? [] : $this->form_validation->get_errors();

        $name = trim((string) ($in['name'] ?? ''));
        if ($name !== '' && $this->categories->nameTaken($name, $ignoreId)) {
            $errors[] = 'A category with this name already exists.';
        }

        $amenities = $in['amenities'] ?? [];
        if (!is_array($amenities)) {
            $errors[] = 'Amenities must be a list.';
        }

        if ($errors) {
            $this->api->respond([
                'success' => false,
                'message' => 'Please correct the errors below.',
                'errors'  => $errors,
            ], 422);
        }

        $clean = array_values(array_filter(
            array_map(fn ($a) => trim((string) $a), $amenities),
            fn ($a) => $a !== ''
        ));

        return [
            'name'          => $name,
            'description'   => trim((string) ($in['description'] ?? '')) ?: null,
            'max_occupancy' => (int) $in['max_occupancy'],
            'amenities'     => json_encode($clean),
            'is_active'     => isset($in['is_active']) ? (int) (bool) $in['is_active'] : 1,
        ];
    }

    public function index()
    {
        $this->api->require_method('GET');

        $this->api->respond([
            'success' => true,
            'data'    => array_map([$this, 'shape'], $this->categories->allOrdered()),
        ]);
    }

    public function store()
    {
        $this->api->require_method('POST');

        $id = $this->categories->insert($this->validateInput($this->api->body()));

        $this->api->respond([
            'success' => true,
            'message' => 'Category created.',
            'data'    => $this->shape($this->categories->find($id)),
        ], 201);
    }

    public function update($id)
    {
        $this->api->require_method('PUT');

        if (!$this->categories->find((int) $id)) {
            $this->api->respond_error('Category not found.', 404);
        }

        $this->categories->update((int) $id, $this->validateInput($this->api->body(), (int) $id));

        $this->api->respond([
            'success' => true,
            'message' => 'Category updated.',
            'data'    => $this->shape($this->categories->find((int) $id)),
        ]);
    }

    public function destroy($id)
    {
        $this->api->require_method('DELETE');

        if (!$this->categories->find((int) $id)) {
            $this->api->respond_error('Category not found.', 404);
        }

        $inUse = $this->rooms->countByCategory($id);
        if ($inUse > 0) {
            $this->api->respond_error(
                "This category is used by {$inUse} room(s). Deactivate it instead of deleting.",
                409
            );
        }

        $this->categories->delete((int) $id);

        $this->api->respond(['success' => true, 'message' => 'Category deleted.']);
    }
}