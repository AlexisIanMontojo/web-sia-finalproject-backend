<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class XenditWebhookController extends Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->call->model('ReservationPaymentsModel', 'payments');
    }

    /** POST /api/payments/xendit/webhook */
    public function handle()
    {
        $this->api->require_method('POST');

        $expected = $this->payments->env('XENDIT_WEBHOOK_TOKEN');
        $given    = $_SERVER['HTTP_X_CALLBACK_TOKEN'] ?? '';

        if (!$expected || !hash_equals($expected, $given)) {
            error_log('Xendit webhook rejected: callback token does not match.');
            $this->api->respond_error('Invalid callback token.', 401);
        }

        $in         = $this->api->body();
        $externalId = (string) ($in['external_id'] ?? '');

        $row = $externalId !== '' ? $this->payments->findByExternalId($externalId) : null;

        // Answer 200 for unknown payments, so Xendit doesn't keep retrying.
        if (!$row) {
            $this->api->respond(['success' => true, 'message' => 'Ignored: unknown payment.']);
        }

        if ($row['status'] === 'paid') {
            $this->api->respond(['success' => true, 'message' => 'Already recorded.']);
        }

        // Never trust the callback body alone: confirm the invoice with Xendit.
        [$code, $invoice] = $this->payments->fetchInvoice($row['invoice_id'] ?: ($in['id'] ?? ''));

        if ($code !== 200) {
            $this->api->respond_error('Could not verify the invoice with the provider.', 502);
        }

        if (abs((float) ($invoice['amount'] ?? 0) - (float) $row['amount']) > 0.01) {
            error_log("Xendit webhook amount mismatch for {$externalId}.");
            $this->api->respond(['success' => true, 'message' => 'Ignored: amount mismatch.']);
        }

        $status = strtoupper((string) ($invoice['status'] ?? ''));

        if ($status === 'PAID' || $status === 'SETTLED') {
            $this->payments->update((int) $row['id'], [
                'status'  => 'paid',
                'paid_at' => date('Y-m-d H:i:s'),
            ]);
        } elseif ($status === 'EXPIRED') {
            $this->payments->update((int) $row['id'], ['status' => 'expired']);
        }

        // The hotel still confirms the booking. A paid downpayment doesn't confirm it automatically.
        $this->api->respond(['success' => true, 'message' => 'Processed.']);
    }
}