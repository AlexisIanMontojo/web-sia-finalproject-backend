<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class RoomsController extends Controller
{
    const STATUSES = ['available', 'maintenance', 'out_of_service'];

    const IMAGE_TYPES = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];

    const IMAGE_MAX_BYTES = 3 * 1024 * 1024; // 3 MB

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
        $in['status']    = trim((string) ($in['status'] ?? 'available'));
        $in['is_active'] = $in['is_active'] ?? true;

        $_POST = $in;
        $this->call->library('form_validation');

        $isValid = $this->form_validation->validate([
            'room_number|Room number' => 'required|max_length[20]',
            'category_id|Category'    => 'required|numeric',
            'capacity|Capacity'       => 'required|numeric|greater_than_equal_to[1]|less_than_equal_to[20]',
        ]);

        $errors = $isValid ? [] : $this->form_validation->get_errors();

        if (!in_array($in['status'], self::STATUSES, true)) {
            $errors[] = 'Choose a valid status: ' . implode(', ', self::STATUSES) . '.';
        }

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
            'image_url'    => trim((string) ($in['image_url'] ?? '')) ?: null,
            'is_active'    => filter_var($in['is_active'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0,
        ];
    }

    /**
     * Saves an uploaded image and returns its public path.
     * Accepts multipart/form-data with a field named "image".
     */
    public function upload()
    {
        $this->api->require_method('POST');

        if (empty($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
            $this->api->respond_error('Choose an image to upload.', 422);
        }

        $file = $_FILES['image'];

        if ($file['size'] > self::IMAGE_MAX_BYTES) {
            $this->api->respond_error('The image must be 3 MB or smaller.', 422);
        }

        // Check the real file contents, not the name or the browser's type.
        $finfo    = new finfo(FILEINFO_MIME_TYPE);
        $mime     = $finfo->file($file['tmp_name']);
        $extension = self::IMAGE_TYPES[$mime] ?? null;

        if (!$extension || !getimagesize($file['tmp_name'])) {
            $this->api->respond_error('Upload a JPG, PNG, or WebP image.', 422);
        }

        $dir = FCPATH . 'uploads' . DIRECTORY_SEPARATOR . 'rooms';
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            error_log('Could not create the room image folder: ' . $dir);
            $this->api->respond_error('Image storage is not available.', 500);
        }

        $name = bin2hex(random_bytes(12)) . '.' . $extension;

        if (!move_uploaded_file($file['tmp_name'], $dir . DIRECTORY_SEPARATOR . $name)) {
            error_log('Failed to move uploaded room image to ' . $dir);
            $this->api->respond_error('The image could not be saved. Try again.', 500);
        }

        $this->api->respond([
            'success'   => true,
            'message'   => 'Image uploaded.',
            'image_url' => '/uploads/rooms/' . $name,
        ], 201);
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