<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class AdminServiceCatalogController extends Controller
{
    const CATEGORIES = ['laundry', 'food', 'spa', 'meeting', 'other'];
    const UNITS      = ['per_item', 'per_person', 'per_hour', 'per_stay', 'per_kilo'];

    public function __construct()
    {
        parent::__construct();
        $this->call->model('ServiceCatalogModel', 'services');
    }

    private function shape(array $s)
    {
        return [
            'id'                => (int) $s['id'],
            'name'              => $s['name'],
            'description'       => $s['description'],
            'category'          => $s['category'],
            'price'             => (float) $s['price'],
            'unit'              => $s['unit'],
            'requires_schedule' => (bool) $s['requires_schedule'],
            'duration_minutes'  => (int) $s['duration_minutes'],
            'capacity'          => (int) $s['capacity'],
            'is_active'         => (bool) $s['is_active'],
        ];
    }

    private function validate(array $in)
    {
        $errors   = [];
        $name     = trim((string) ($in['name'] ?? ''));
        $category = (string) ($in['category'] ?? '');
        $unit     = (string) ($in['unit'] ?? '');
        $price    = $in['price'] ?? null;
        $duration = (int) ($in['duration_minutes'] ?? 60);
        $capacity = (int) ($in['capacity'] ?? 1);

        if ($name === '' || mb_strlen($name) > 100) {
            $errors[] = 'Name is required (100 characters maximum).';
        }
        if (!in_array($category, self::CATEGORIES, true)) {
            $errors[] = 'Choose a category.';
        }
        if (!in_array($unit, self::UNITS, true)) {
            $errors[] = 'Choose a pricing unit.';
        }
        if (!is_numeric($price) || (float) $price < 0) {
            $errors[] = 'Price must be zero or more.';
        }
        if ($duration < 15 || $duration > 480) {
            $errors[] = 'Duration must be from 15 to 480 minutes.';
        }
        if ($capacity < 1 || $capacity > 50) {
            $errors[] = 'Capacity must be from 1 to 50.';
        }

        if ($errors) {
            $this->api->respond([
                'success' => false,
                'message' => 'Please correct the service.',
                'errors'  => $errors,
            ], 422);
        }

        return [
            'name'              => $name,
            'description'       => trim((string) ($in['description'] ?? '')) ?: null,
            'category'          => $category,
            'price'             => round((float) $price, 2),
            'unit'              => $unit,
            'requires_schedule' => filter_var($in['requires_schedule'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 1 : 0,
            'duration_minutes'  => $duration,
            'capacity'          => $capacity,
            'is_active'         => filter_var($in['is_active'] ?? true, FILTER_VALIDATE_BOOLEAN) ? 1 : 0,
        ];
    }

    /** GET /api/admin/service-catalog */
    public function index()
    {
        $this->api->require_method('GET');

        $this->api->respond([
            'success' => true,
            'data'    => array_map([$this, 'shape'], $this->services->allOrdered()),
        ]);
    }

    /** POST /api/admin/service-catalog */
    public function store()
    {
        $this->api->require_method('POST');

        $id = $this->services->insert($this->validate($this->api->body()));

        $this->api->respond([
            'success' => true,
            'message' => 'Service added.',
            'data'    => $this->shape($this->services->find((int) $id)),
        ], 201);
    }

    /** PUT /api/admin/service-catalog/{id} */
    public function update($id)
    {
        $this->api->require_method('PUT');

        if (!$this->services->find((int) $id)) {
            $this->api->respond_error('Service not found.', 404);
        }

        $this->services->update((int) $id, $this->validate($this->api->body()));

        $this->api->respond([
            'success' => true,
            'message' => 'Service updated.',
            'data'    => $this->shape($this->services->find((int) $id)),
        ]);
    }
}