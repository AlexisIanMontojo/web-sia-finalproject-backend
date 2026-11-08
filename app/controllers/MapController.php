<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class MapController extends Controller
{
    const ZOOM = 15;

    public function __construct()
    {
        parent::__construct();
        $this->call->model('HotelInfoModel', 'hotel');
    }

    private function mapPayload(array $h)
    {
        $lat = (float) $h['latitude'];
        $lng = (float) $h['longitude'];

        return [
            'name'            => $h['name'],
            'address'         => $h['address'],
            'latitude'        => $lat,
            'longitude'       => $lng,
            'zoom'            => self::ZOOM,
            'embed_url'       => 'https://www.google.com/maps?q=' . $lat . ',' . $lng
                                 . '&z=' . self::ZOOM . '&output=embed',
            'directions_url'  => 'https://www.google.com/maps/dir/?api=1&destination=' . $lat . ',' . $lng,
        ];
    }

    /** Public: the map for the customer pages. */
    public function show()
    {
        $this->api->require_method('GET');

        $h = $this->hotel->current();

        if (!$h || $h['latitude'] === null || $h['longitude'] === null) {
            $this->api->respond(['success' => true, 'configured' => false, 'map' => null]);
        }

        $this->api->respond([
            'success'    => true,
            'configured' => true,
            'map'        => $this->mapPayload($h),
        ]);
    }

    /** Admin: set the hotel's location. */
    public function update()
    {
        $this->api->require_method('PUT');

        $in = $this->api->body();

        $_POST = $in;
        $this->call->library('form_validation');

        $valid = $this->form_validation->validate([
            'latitude|Latitude'   => 'required|numeric',
            'longitude|Longitude' => 'required|numeric',
        ]);

        $errors = $valid ? [] : $this->form_validation->get_errors();

        $lat = (float) ($in['latitude'] ?? 0);
        $lng = (float) ($in['longitude'] ?? 0);

        if (!$errors && (abs($lat) > 90 || abs($lng) > 180)) {
            $errors[] = 'Latitude must be between -90 and 90, and longitude between -180 and 180.';
        }

        if ($errors) {
            $this->api->respond([
                'success' => false,
                'message' => 'Please correct the location.',
                'errors'  => $errors,
            ], 422);
        }

        $this->hotel->saveDetails([
            'latitude'  => round($lat, 7),
            'longitude' => round($lng, 7),
        ]);

        $this->api->respond([
            'success'    => true,
            'message'    => 'Location saved.',
            'configured' => true,
            'map'        => $this->mapPayload($this->hotel->current()),
        ]);
    }
}