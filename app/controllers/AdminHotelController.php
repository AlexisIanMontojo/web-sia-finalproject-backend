<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class AdminHotelController extends Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->call->model('HotelInfoModel', 'hotel');
    }
    // start
    private function shape(array $h)
    {
        return [
            'name'           => $h['name'],
            'address'        => $h['address'],
            'phone'          => $h['phone'],
            'email'          => $h['email'],
            'check_in_time'  => $h['check_in_time'],
            'check_out_time' => $h['check_out_time'],
            'amenities'      => $h['amenities'],
            'policies'       => $h['policies'],
            'latitude'       => $h['latitude'] === null ? null : (float) $h['latitude'],
            'longitude'      => $h['longitude'] === null ? null : (float) $h['longitude'],
        ];
    }
    /** Accepts a list as an array or as text with one item per line. */
    private function lines($value)
    {
        $items = is_array($value) ? $value : preg_split('/\r\n|\r|\n/', (string) $value);

        return array_values(array_filter(
            array_map(fn ($i) => trim((string) $i), $items),
            fn ($i) => $i !== ''
        ));
    }

    public function show()
    {
        $this->api->require_method('GET');

        $info = $this->hotel->current();
        if (!$info) {
            $this->api->respond_error('Hotel information is not set up yet.', 404);
        }

        $this->api->respond(['success' => true, 'hotel' => $this->shape($info)]);
    }

    public function update()
    {
        $this->api->require_method('PUT');

        $in = $this->api->body();

        $_POST = $in;
        $this->call->library('form_validation');

        $valid = $this->form_validation->validate([
            'name|Hotel name'        => 'required|max_length[150]',
            'address|Address'        => 'required|max_length[255]',
            'phone|Phone'            => 'max_length[30]',
            'check_in_time|Check-in' => 'max_length[20]',
            'check_out_time|Check-out' => 'max_length[20]',
        ]);

        $errors = $valid ? [] : $this->form_validation->get_errors();

        $email = trim((string) ($in['email'] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Enter a valid email address.';
        }

        if ($errors) {
            $this->api->respond([
                'success' => false,
                'message' => 'Please correct the errors below.',
                'errors'  => $errors,
            ], 422);
        }

        $this->hotel->saveDetails([
            'name'           => trim((string) $in['name']),
            'address'        => trim((string) $in['address']),
            'phone'          => trim((string) ($in['phone'] ?? '')) ?: null,
            'email'          => $email ?: null,
            'check_in_time'  => trim((string) ($in['check_in_time'] ?? '')) ?: null,
            'check_out_time' => trim((string) ($in['check_out_time'] ?? '')) ?: null,
            'amenities'      => json_encode($this->lines($in['amenities'] ?? [])),
            'policies'       => json_encode($this->lines($in['policies'] ?? [])),
        ]);

        $this->api->respond([
            'success' => true,
            'message' => 'Hotel details saved.',
            'hotel'   => $this->shape($this->hotel->current()),
        ]);
    }
}