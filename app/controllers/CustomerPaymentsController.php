<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class CustomerPaymentsController extends Controller
{
    const DEFAULT_PERCENT = 30;

    public function __construct()
    {
        parent::__construct();
        $this->call->model('ReservationsModel', 'reservations');
        $this->call->model('ReservationPaymentsModel', 'payments');
        $this->call->model('ReservationServicesModel', 'serviceRequests');
        $this->call->model('UsersModel', 'usersModel');
    }

    private function currentUserId()
    {
        $claims = $this->api->require_jwt();
        return (int) $claims['sub'];
    }

    private function depositFor($total)
    {
        $percent = (int) $this->payments->env('DOWNPAYMENT_PERCENT', self::DEFAULT_PERCENT);
        $percent = min(100, max(1, $percent));

        return round($total * $percent / 100, 2);
    }

    private function frontendUrl()
    {
        return rtrim(
            $this->payments->env('FRONTEND_URL', 'https://web-sia-finalproject-frontend.vercel.app'),
            '/'
        );
    }

    private function shapePayment(array $p)
    {
        return [
            'id'          => (int) $p['id'],
            'kind'        => $p['kind'],
            'amount'      => (float) $p['amount'],
            'status'      => $p['status'],
            'invoice_url' => $p['invoice_url'],
            'paid_at'     => $p['paid_at'],
            'created_at'  => $p['created_at'],
        ];
    }

    /** GET /api/customer/reservations/{id}/payments */
    public function index($id)
    {
        $this->api->require_method('GET');

        $r = $this->reservations->findOwned((int) $id, $this->currentUserId());
        if (!$r) {
            $this->api->respond_error('Reservation not found.', 404);
        }

        $externalId = (string) ($_GET['external_id'] ?? '');
        if ($externalId !== '') {
            $this->payments->syncPendingInvoices((int) $r['id'], $externalId);
        }
        $total = (float) $r['total_amount'];
        $paid  = $this->payments->paidReservationTotal((int) $r['id']);
        $open  = in_array($r['status'], ['pending', 'confirmed'], true);

        $this->api->respond([
            'success'        => true,
            'total'          => $total,
            'paid'           => $paid,
            'balance'        => max(0, round($total - $paid, 2)),
            'downpayment_due' => ($open && $paid <= 0) ? $this->depositFor($total) : 0,
            'data'           => array_map(
                [$this, 'shapePayment'],
                $this->payments->forReservation((int) $r['id'])
            ),
        ]);
    }

    /** POST /api/customer/reservations/{id}/downpayment */
    public function downpayment($id)
    {
        $this->api->require_method('POST');
        $userId = $this->currentUserId();

        $r = $this->reservations->findOwned((int) $id, $userId);
        if (!$r) {
            $this->api->respond_error('Reservation not found.', 404);
        }

        if (!in_array($r['status'], ['pending', 'confirmed'], true)) {
            $this->api->respond_error('A downpayment can only be made for a pending or confirmed booking.', 409);
        }

        if ($this->payments->paidReservationTotal((int) $r['id']) > 0) {
            $this->api->respond_error('A payment is already recorded for this booking.', 409);
        }

        // Reuse an open invoice rather than creating duplicates.
        foreach ($this->payments->forReservation((int) $r['id']) as $p) {
            if ($p['status'] === 'pending' && $p['kind'] === 'downpayment' && !empty($p['invoice_url'])) {
                $this->api->respond([
                    'success'     => true,
                    'message'     => 'Continue to your open payment.',
                    'invoice_url' => $p['invoice_url'],
                    'amount'      => (float) $p['amount'],
                ]);
            }
        }

        $amount = $this->depositFor((float) $r['total_amount']);
        if ($amount <= 0) {
            $this->api->respond_error('Nothing to pay for this booking.', 409);
        }

        $user       = $this->usersModel->find($userId);
        $externalId = 'DP-' . (int) $r['id'] . '-' . bin2hex(random_bytes(6));
        $front      = $this->frontendUrl();
        $returnUrl  = $front . '/payment-return?reservation_id=' . (int) $r['id']
            . '&external_id=' . rawurlencode($externalId);

        [$status, $body] = $this->payments->createInvoice([
            'external_id'          => $externalId,
            'amount'               => $amount,
            'payer_email'          => $user['email'],
            'description'          => "Downpayment for reservation {$r['reservation_code']}",
            'currency'             => 'PHP',
            'invoice_duration'     => 86400,
            'success_redirect_url' => $returnUrl . '&result=success',
            'failure_redirect_url' => $returnUrl . '&result=failed',
        ]);

        if ($status < 200 || $status >= 300 || empty($body['invoice_url'])) {
            error_log('Xendit invoice failed: HTTP ' . $status . ' ' . json_encode($body));
            $this->api->respond_error('The payment provider could not create the invoice. Try again.', 502);
        }

        $this->payments->insert([
            'reservation_id' => (int) $r['id'],
            'provider'       => 'xendit',
            'kind'           => 'downpayment',
            'external_id'    => $externalId,
            'invoice_id'     => (string) ($body['id'] ?? ''),
            'invoice_url'    => $body['invoice_url'],
            'amount'         => $amount,
            'status'         => 'pending',
            'paid_at'        => null,
        ]);

        $this->api->respond([
            'success'     => true,
            'message'     => 'Invoice created.',
            'invoice_url' => $body['invoice_url'],
            'amount'      => $amount,
        ], 201);
    }

    /** POST /api/customer/reservations/{id}/service-payment */
    public function servicePayment($id)
    {
        $this->api->require_method('POST');
        $userId = $this->currentUserId();
        $reservation = $this->reservations->findOwned((int) $id, $userId);

        if (!$reservation) {
            $this->api->respond_error('Reservation not found.', 404);
        }

        if (!$this->payments->acquireServicePaymentLock((int) $reservation['id'])) {
            $this->api->respond_error('A service payment is already being prepared. Please try again shortly.', 409);
        }

        try {
            $result = $this->createServiceInvoice($reservation, $userId);
        } catch (\Throwable $e) {
            error_log('Xendit service invoice preparation failed: ' . get_class($e) . ': ' . $e->getMessage());
            $result = [
                'error'  => 'The service invoice could not be prepared. Please try again.',
                'status' => 500,
            ];
        } finally {
            $this->payments->releaseServicePaymentLock((int) $reservation['id']);
        }

        if (isset($result['error'])) {
            $this->api->respond_error($result['error'], $result['status']);
        }

        $this->api->respond([
            'success'     => true,
            'message'     => $result['message'],
            'invoice_url' => $result['invoice_url'],
            'amount'      => $result['amount'],
        ], $result['http_status']);
    }

    private function createServiceInvoice(array $reservation, $userId)
    {
        $openPayment = $this->payments->pendingServicePayment((int) $reservation['id']);
        if ($openPayment) {
            if (empty($openPayment['invoice_url'])) {
                return [
                    'error'  => 'A service invoice is still being prepared. Try again shortly.',
                    'status' => 409,
                ];
            }

            return [
                'message'     => 'Continue to your open service payment.',
                'invoice_url' => $openPayment['invoice_url'],
                'amount'      => (float) $openPayment['amount'],
                'http_status' => 200,
            ];
        }

        $items = array_values(array_filter(
            $this->serviceRequests->forReservation((int) $reservation['id']),
            static fn ($item) => $item['status'] === 'completed'
                && $item['billing_status'] === 'unpaid'
        ));
        if (!$items) {
            return [
                'error'  => 'There are no completed, unpaid services to pay.',
                'status' => 409,
            ];
        }

        $amount = round(array_sum(array_map(
            static fn ($item) => (float) $item['total_amount'],
            $items
        )), 2);
        if ($amount <= 0) {
            return ['error' => 'The service balance is invalid.', 'status' => 409];
        }

        $user = $this->usersModel->find($userId);
        if (!$user || empty($user['email'])) {
            return [
                'error'  => 'A valid customer email is required for payment.',
                'status' => 409,
            ];
        }

        $externalId = 'SV-' . (int) $reservation['id'] . '-' . bin2hex(random_bytes(6));
        $returnUrl = $this->frontendUrl() . '/payment-return?reservation_id='
            . (int) $reservation['id'] . '&external_id=' . rawurlencode($externalId);

        [$status, $body] = $this->payments->createInvoice([
            'external_id'          => $externalId,
            'amount'               => $amount,
            'payer_email'          => $user['email'],
            'description'          => 'Additional services for reservation ' . $reservation['reservation_code'],
            'currency'             => 'PHP',
            'invoice_duration'     => 86400,
            'success_redirect_url' => $returnUrl . '&result=success',
            'failure_redirect_url' => $returnUrl . '&result=failed',
        ]);

        if ($status < 200 || $status >= 300 || empty($body['invoice_url'])) {
            error_log('Xendit service invoice failed: HTTP ' . $status . ' ' . json_encode($body));
            return [
                'error'  => 'The payment provider could not create the invoice. Try again.',
                'status' => 502,
            ];
        }

        try {
            $this->payments->transaction();
            $paymentId = $this->payments->insert([
                'reservation_id' => (int) $reservation['id'],
                'provider'       => 'xendit',
                'kind'           => 'service',
                'external_id'    => $externalId,
                'invoice_id'     => (string) ($body['id'] ?? ''),
                'invoice_url'    => $body['invoice_url'],
                'amount'         => $amount,
                'status'         => 'pending',
                'paid_at'        => null,
            ]);
            if (!$paymentId) {
                throw new \RuntimeException('Unable to record the service invoice.');
            }

            $this->payments->linkServiceItems((int) $paymentId, $items);
            $this->payments->commit();
        } catch (\Throwable $e) {
            $this->payments->rollback();
            error_log('Xendit service invoice record failed: ' . get_class($e) . ': ' . $e->getMessage());
            return [
                'error'  => 'The invoice was created but could not be recorded. Please contact the hotel before retrying.',
                'status' => 500,
            ];
        }

        return [
            'message'     => 'Service invoice created.',
            'invoice_url' => $body['invoice_url'],
            'amount'      => $amount,
            'http_status' => 201,
        ];
    }
}