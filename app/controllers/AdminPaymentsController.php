<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class AdminPaymentsController extends Controller
{
    const FILTERS = ['pending', 'paid', 'expired', 'failed'];

    public function __construct()
    {
        parent::__construct();
        $this->call->model('ReservationPaymentsModel', 'payments');
    }

    public function index()
    {
        $this->api->require_method('GET');

        $status = (string) ($_GET['status'] ?? '');
        if ($status !== '' && !in_array($status, self::FILTERS, true)) {
            $this->api->respond_error('Unknown payment status filter.', 422);
        }

        $this->api->respond([
            'success' => true,
            'data'    => $this->payments->allForAdmin($status),
        ]);
    }
}
