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

    public function markCashPaid($id)
    {
        $this->api->require_method('POST');
        $payment = $this->payments->find((int) $id);

        if (!$payment) {
            $this->api->respond_error('Payment not found.', 404);
        }
        if ($payment['provider'] !== 'cash') {
            $this->api->respond_error('Only cash payments can be recorded here.', 409);
        }
        if ($payment['status'] !== 'pending') {
            $this->api->respond_error('This payment is no longer pending.', 409);
        }

        try {
            $this->payments->recordPaid($payment, date('Y-m-d H:i:s'));
        } catch (\Throwable $e) {
            error_log('Cash payment recording failed: ' . get_class($e) . ': ' . $e->getMessage());
            $this->api->respond_error('Could not record the cash payment. Try again.', 500);
        }

        $this->api->respond(['success' => true, 'message' => 'Cash payment recorded as paid.']);
    }
}
