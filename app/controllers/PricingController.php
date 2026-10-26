<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class PricingController extends Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->call->model('RoomPricingModel', 'pricing');
        $this->call->model('RoomCategoriesModel', 'categories');
    }

    private function shape(array $p)
    {
        return [
            'id'                 => (int) $p['id'],
            'category_id'        => (int) $p['category_id'],
            'category_name'      => $p['category_name'] ?? null,
            'nightly_rate'       => (float) $p['nightly_rate'],
            'extra_guest_charge' => (float) $p['extra_guest_charge'],
            'cleaning_fee'       => (float) $p['cleaning_fee'],
            'effective_from'     => $p['effective_from'],
            'effective_until'    => $p['effective_until'],
            'is_active'          => (bool) $p['is_active'],
        ];
    }

    private function isDate($value)
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return false;
        }
        [$y, $m, $d] = array_map('intval', explode('-', $value));
        return checkdate($m, $d, $y);
    }

    private function validateInput(array $in, $ignoreId = 0)
    {
        $in['extra_guest_charge'] = $in['extra_guest_charge'] ?? 0;
        $in['cleaning_fee']       = $in['cleaning_fee'] ?? 0;
        $in['is_active']          = $in['is_active'] ?? true;

        $_POST = $in;
        $this->call->library('form_validation');

        $isValid = $this->form_validation->validate([
            'category_id|Category'                  => 'required|numeric',
            'nightly_rate|Nightly rate'             => 'required|numeric|greater_than[0]',
            'extra_guest_charge|Extra guest charge' => 'required|numeric|greater_than_equal_to[0]',
            'cleaning_fee|Cleaning fee'             => 'required|numeric|greater_than_equal_to[0]',
            'effective_from|Effective from'         => 'required|pattern[date_ymd]',
        ]);

        $errors = $isValid ? [] : $this->form_validation->get_errors();

        $categoryId = (int) ($in['category_id'] ?? 0);
        if ($categoryId > 0 && !$this->categories->find($categoryId)) {
            $errors[] = 'Select a valid category.';
        }

        $from  = (string) ($in['effective_from'] ?? '');
        $until = trim((string) ($in['effective_until'] ?? '')) ?: null;

        if ($until !== null && (!$this->isDate($until) || $until < $from)) {
            $errors[] = 'End date must be on or after the start date.';
        }

        $isActive = filter_var($in['is_active'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;

        if (!$errors && $isActive && $this->pricing->overlaps($categoryId, $from, $until, $ignoreId)) {
            $errors[] = 'This category already has an active price for these dates. End the other price first.';
        }

        if ($errors) {
            $this->api->respond([
                'success' => false,
                'message' => 'Please correct the errors below.',
                'errors'  => $errors,
            ], 422);
        }

        return [
            'category_id'        => $categoryId,
            'nightly_rate'       => (float) $in['nightly_rate'],
            'extra_guest_charge' => (float) $in['extra_guest_charge'],
            'cleaning_fee'       => (float) $in['cleaning_fee'],
            'effective_from'     => $from,
            'effective_until'    => $until,
            'is_active'          => $isActive,
        ];
    }

    public function index()
    {
        $this->api->require_method('GET');

        $this->api->respond([
            'success' => true,
            'data'    => array_map([$this, 'shape'], $this->pricing->allWithCategory()),
        ]);
    }

    public function store()
    {
        $this->api->require_method('POST');

        $id = $this->pricing->insert($this->validateInput($this->api->body()));

        $this->api->respond([
            'success' => true,
            'message' => 'Price created.',
            'data'    => $this->shape($this->pricing->findWithCategory($id)),
        ], 201);
    }

    public function update($id)
    {
        $this->api->require_method('PUT');

        if (!$this->pricing->find((int) $id)) {
            $this->api->respond_error('Price not found.', 404);
        }

        $this->pricing->update((int) $id, $this->validateInput($this->api->body(), (int) $id));

        $this->api->respond([
            'success' => true,
            'message' => 'Price updated.',
            'data'    => $this->shape($this->pricing->findWithCategory($id)),
        ]);
    }

    public function destroy($id)
    {
        $this->api->require_method('DELETE');

        if (!$this->pricing->find((int) $id)) {
            $this->api->respond_error('Price not found.', 404);
        }

        $this->pricing->delete((int) $id);

        $this->api->respond(['success' => true, 'message' => 'Price deleted.']);
    }
}