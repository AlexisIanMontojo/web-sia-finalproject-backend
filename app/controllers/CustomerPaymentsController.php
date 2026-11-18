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
            'provider'    => $p['provider'],
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

        $r = $this->reservations->findOwnedWithFinancials((int) $id, $this->currentUserId());
        if (!$r) {
            $this->api->respond_error('Reservation not found.', 404);
        }

        $externalId = (string) ($_GET['external_id'] ?? '');
        if ($externalId !== '') {
            $this->payments->syncPendingInvoices((int) $r['id'], $externalId);
        }
        $reservationTotal = (float) $r['total_amount'];
        $reservationPaid  = (float) $r['reservation_paid'];
        $serviceTotal = (float) $r['service_total'];
        $servicePaid  = (float) $r['service_paid'];
        $reservationBalance = max(0, round($reservationTotal - $reservationPaid, 2));
        $serviceBalance = max(0, round($serviceTotal - $servicePaid, 2));
        $open  = in_array($r['status'], ['pending', 'confirmed', 'checked_in', 'checked_out'], true);

        $this->api->respond([
            'success'        => true,
            'total'          => $reservationTotal,
            'paid'           => $reservationPaid,
            'balance'        => $reservationBalance,
            'reservation_total'   => $reservationTotal,
            'reservation_paid'    => $reservationPaid,
            'reservation_balance' => $reservationBalance,
            'service_total'       => $serviceTotal,
            'service_paid'        => $servicePaid,
            'service_balance'     => $serviceBalance,
            'open'           => $open,
            'downpayment_due' => ($open && $reservationPaid <= 0)
                ? $this->depositFor($reservationTotal)
                : 0,
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
        $this->startReservationPayment((int) $id, 'downpayment', 'xendit');
    }

    /** POST /api/customer/reservations/{id}/payment */
    public function reservationPayment($id)
    {
        $this->api->require_method('POST');
        $in = $this->api->body();
        $amountType = (string) ($in['amount_type'] ?? 'downpayment');
        $method = (string) ($in['method'] ?? 'xendit');

        if (!in_array($amountType, ['downpayment', 'full', 'balance'], true)) {
            $this->api->respond_error('Choose a valid payment amount.', 422);
        }
        if (!in_array($method, ['xendit', 'cash'], true)) {
            $this->api->respond_error('Choose Xendit or cash.', 422);
        }

        $this->startReservationPayment((int) $id, $amountType, $method);
    }

    private function startReservationPayment($id, $amountType, $method)
    {
        $userId = $this->currentUserId();

        $r = $this->reservations->findOwned($id, $userId);
        if (!$r) {
            $this->api->respond_error('Reservation not found.', 404);
        }

        if (!in_array($r['status'], ['pending', 'confirmed', 'checked_in', 'checked_out'], true)) {
            $this->api->respond_error('Payment is unavailable for cancelled or rejected reservations.', 409);
        }

        if (!$this->payments->acquireReservationPaymentLock((int) $r['id'])) {
            $this->api->respond_error('A payment is already being prepared. Please try again shortly.', 409);
        }

        try {
            $result = $this->createReservationPayment($r, $userId, $amountType, $method);
        } catch (\Throwable $e) {
            error_log('Reservation payment preparation failed: ' . get_class($e) . ': ' . $e->getMessage());
            $result = [
                'error'  => 'The payment could not be prepared. Please try again.',
                'status' => 500,
            ];
        } finally {
            $this->payments->releaseReservationPaymentLock((int) $r['id']);
        }

        if (isset($result['error'])) {
            $this->api->respond_error($result['error'], $result['status']);
        }

        $this->api->respond([
            'success'     => true,
            'message'     => $result['message'],
            'provider'    => $result['provider'],
            'status'      => $result['status'],
            'invoice_url' => $result['invoice_url'],
            'amount'      => $result['amount'],
        ], $result['http_status']);
    }

    private function createReservationPayment(array $reservation, $userId, $amountType, $method)
    {
        $paid = $this->payments->paidReservationTotal((int) $reservation['id']);
        $balance = max(0, round((float) $reservation['total_amount'] - $paid, 2));
        if ($amountType === 'downpayment') {
            if ($paid > 0) {
                return ['error' => 'A payment is already recorded. Pay the remaining balance instead.', 'status' => 409];
            }
            $amount = $this->depositFor((float) $reservation['total_amount']);
            $kind = 'downpayment';
        } elseif ($amountType === 'balance') {
            if ($paid <= 0) {
                return ['error' => 'A downpayment must be recorded before paying the remaining balance.', 'status' => 409];
            }
            $amount = $balance;
            $kind = 'balance';
        } else {
            $amount = $balance;
            $kind = $paid > 0 ? 'balance' : 'full';
        }

        if ($amount <= 0) {
            return ['error' => 'Nothing is due for this booking.', 'status' => 409];
        }

        $pending = $this->payments->pendingReservationPayment((int) $reservation['id']);
        if ($pending) {
            if (
                $pending['provider'] === $method
                && $pending['kind'] === $kind
                && abs((float) $pending['amount'] - $amount) < 0.01
            ) {
                return [
                    'message'     => $method === 'cash'
                        ? 'Cash payment is awaiting confirmation.'
                        : 'Continue to your open payment.',
                    'provider'    => $method,
                    'status'      => 'pending',
                    'invoice_url' => $pending['invoice_url'],
                    'amount'      => (float) $pending['amount'],
                    'http_status' => 200,
                ];
            }

            return [
                'error'  => 'Another reservation payment is awaiting confirmation. Complete it or contact the hotel.',
                'status' => 409,
            ];
        }

        $externalId = ($method === 'cash' ? 'CASH-' : strtoupper(substr($kind, 0, 2)) . '-')
            . (int) $reservation['id'] . '-' . bin2hex(random_bytes(6));
        if ($method === 'cash') {
            $paymentId = $this->payments->insert([
                'reservation_id' => (int) $reservation['id'],
                'provider'       => 'cash',
                'kind'           => $kind,
                'external_id'    => $externalId,
                'invoice_id'     => '',
                'invoice_url'    => '',
                'amount'         => $amount,
                'status'         => 'pending',
                'paid_at'        => null,
            ]);
            if (!$paymentId) {
                throw new \RuntimeException('Unable to record the cash payment request.');
            }

            return [
                'message'     => 'Cash payment requested. Please pay the hotel in person.',
                'provider'    => 'cash',
                'status'      => 'pending',
                'invoice_url' => null,
                'amount'      => $amount,
                'http_status' => 201,
            ];
        }

        $user = $this->usersModel->find($userId);
        if (!$user || empty($user['email'])) {
            return ['error' => 'A valid customer email is required for Xendit payment.', 'status' => 409];
        }

        $front      = $this->frontendUrl();
        $returnUrl  = $front . '/payment-return?reservation_id=' . (int) $reservation['id']
            . '&external_id=' . rawurlencode($externalId);
        $description = $kind === 'downpayment'
            ? "Downpayment for reservation {$reservation['reservation_code']}"
            : ($kind === 'balance'
                ? "Remaining balance for reservation {$reservation['reservation_code']}"
                : "Full reservation payment for {$reservation['reservation_code']}");

        [$status, $body] = $this->payments->createInvoice([
            'external_id'          => $externalId,
            'amount'               => $amount,
            'payer_email'          => $user['email'],
            'description'          => $description,
            'currency'             => 'PHP',
            'invoice_duration'     => 86400,
            'success_redirect_url' => $returnUrl . '&result=success',
            'failure_redirect_url' => $returnUrl . '&result=failed',
        ]);

        if ($status < 200 || $status >= 300 || empty($body['invoice_url'])) {
            error_log('Xendit invoice failed: HTTP ' . $status . ' ' . json_encode($body));
            return ['error' => 'The payment provider could not create the invoice. Try again.', 'status' => 502];
        }

        $paymentId = $this->payments->insert([
            'reservation_id' => (int) $reservation['id'],
            'provider'       => 'xendit',
            'kind'           => $kind,
            'external_id'    => $externalId,
            'invoice_id'     => (string) ($body['id'] ?? ''),
            'invoice_url'    => $body['invoice_url'],
            'amount'         => $amount,
            'status'         => 'pending',
            'paid_at'        => null,
        ]);
        if (!$paymentId) {
            throw new \RuntimeException('Unable to record the Xendit invoice.');
        }

        return [
            'message'     => 'Invoice created.',
            'provider'    => 'xendit',
            'status'      => 'pending',
            'invoice_url' => $body['invoice_url'],
            'amount'      => $amount,
            'http_status' => 201,
        ];
    }

    /** POST /api/customer/reservations/{id}/service-payment */
    public function servicePayment($id)
    {
        $this->api->require_method('POST');
        $in = $this->api->body();
        $method = (string) ($in['method'] ?? 'xendit');
        if (!in_array($method, ['xendit', 'cash'], true)) {
            $this->api->respond_error('Choose Xendit or cash.', 422);
        }

        $userId = $this->currentUserId();
        $reservation = $this->reservations->findOwned((int) $id, $userId);

        if (!$reservation) {
            $this->api->respond_error('Reservation not found.', 404);
        }

        if (!$this->payments->acquireServicePaymentLock((int) $reservation['id'])) {
            $this->api->respond_error('A service payment is already being prepared. Please try again shortly.', 409);
        }

        try {
            $result = $this->createServicePayment($reservation, $userId, $method);
        } catch (\Throwable $e) {
            error_log('Service payment preparation failed: ' . get_class($e) . ': ' . $e->getMessage());
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
            'provider'    => $result['provider'],
            'status'      => $result['status'],
            'invoice_url' => $result['invoice_url'],
            'amount'      => $result['amount'],
        ], $result['http_status']);
    }

    private function createServicePayment(array $reservation, $userId, $method)
    {
        $openPayment = $this->payments->pendingServicePayment((int) $reservation['id']);
        if ($openPayment) {
            if ($openPayment['provider'] !== $method) {
                return [
                    'error'  => 'A different service payment is awaiting confirmation. Complete it or contact the hotel.',
                    'status' => 409,
                ];
            }

            return [
                'message'     => $method === 'cash'
                    ? 'Cash payment is awaiting confirmation.'
                    : 'Continue to your open service payment.',
                'provider'    => $method,
                'status'      => 'pending',
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

        $externalId = ($method === 'cash' ? 'CS-' : 'SV-')
            . (int) $reservation['id'] . '-' . bin2hex(random_bytes(6));

        if ($method === 'cash') {
            try {
                $this->payments->transaction();
                $paymentId = $this->payments->insert([
                    'reservation_id' => (int) $reservation['id'],
                    'provider'       => 'cash',
                    'kind'           => 'service',
                    'external_id'    => $externalId,
                    'invoice_id'     => '',
                    'invoice_url'    => '',
                    'amount'         => $amount,
                    'status'         => 'pending',
                    'paid_at'        => null,
                ]);
                if (!$paymentId) {
                    throw new \RuntimeException('Unable to record the cash service payment.');
                }

                $this->payments->linkServiceItems((int) $paymentId, $items);
                $this->payments->commit();
            } catch (\Throwable $e) {
                $this->payments->rollback();
                error_log('Cash service payment record failed: ' . get_class($e) . ': ' . $e->getMessage());
                return [
                    'error'  => 'The cash payment request could not be recorded. Please try again.',
                    'status' => 500,
                ];
            }

            return [
                'message'     => 'Cash payment requested. Please pay the hotel in person.',
                'provider'    => 'cash',
                'status'      => 'pending',
                'invoice_url'    => '',
                'amount'      => $amount,
                'http_status' => 201,
            ];
        }

        $user = $this->usersModel->find($userId);
        if (!$user || empty($user['email'])) {
            return [
                'error'  => 'A valid customer email is required for payment.',
                'status' => 409,
            ];
        }

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
            'provider'    => 'xendit',
            'status'      => 'pending',
            'invoice_url' => $body['invoice_url'],
            'amount'      => $amount,
            'http_status' => 201,
        ];
    }
}