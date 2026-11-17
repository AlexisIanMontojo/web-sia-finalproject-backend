<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class ReservationPaymentsModel extends Model
{
    protected $table = 'reservation_payments';
    protected $primary_key = 'id';

    protected $fillable = [
        'reservation_id', 'provider', 'kind', 'external_id', 'invoice_id',
        'invoice_url', 'amount', 'status', 'paid_at',
    ];
    protected $guarded = ['id'];

    /** Reads an environment value from $_ENV first, since Dotenv fills that rather than getenv(). */
    public function env($name, $default = null)
    {
        $value = $_ENV[$name] ?? getenv($name);

        return ($value === false || $value === null || $value === '') ? $default : $value;
    }

    public function forReservation($reservationId)
    {
        return $this->db->table($this->table)
            ->where('reservation_id', (int) $reservationId)
            ->order_by('id', 'DESC')
            ->get_all();
    }

    public function findByExternalId($externalId)
    {
        $rows = $this->db->table($this->table)
            ->where('external_id', (string) $externalId)
            ->get_all();

        return $rows[0] ?? null;
    }

    public function paidReservationTotal($reservationId)
    {
        $row = $this->db->raw(
            "SELECT COALESCE(SUM(amount), 0) AS total
             FROM reservation_payments
             WHERE reservation_id = ? AND status = 'paid' AND kind <> 'service'",
            [(int) $reservationId]
        )->fetch(PDO::FETCH_ASSOC);

        return (float) ($row['total'] ?? 0);
    }

    public function pendingServicePayment($reservationId)
    {
        $rows = $this->db->raw(
            "SELECT *
             FROM reservation_payments
             WHERE reservation_id = ? AND kind = 'service' AND status = 'pending'
             ORDER BY id DESC
             LIMIT 1",
            [(int) $reservationId]
        )->fetchAll(PDO::FETCH_ASSOC);

        return $rows[0] ?? null;
    }

    public function pendingReservationPayment($reservationId)
    {
        $rows = $this->db->raw(
            "SELECT *
             FROM reservation_payments
             WHERE reservation_id = ? AND kind <> 'service' AND status = 'pending'
             ORDER BY id DESC
             LIMIT 1",
            [(int) $reservationId]
        )->fetchAll(PDO::FETCH_ASSOC);

        return $rows[0] ?? null;
    }

    public function acquireReservationPaymentLock($reservationId)
    {
        $row = $this->db->raw(
            'SELECT GET_LOCK(?, 5) AS acquired',
            ['hotel-reservation-payment-' . (int) $reservationId]
        )->fetch(PDO::FETCH_ASSOC);

        return (int) ($row['acquired'] ?? 0) === 1;
    }

    public function releaseReservationPaymentLock($reservationId)
    {
        $this->db->raw(
            'SELECT RELEASE_LOCK(?)',
            ['hotel-reservation-payment-' . (int) $reservationId]
        );
    }

    public function acquireServicePaymentLock($reservationId)
    {
        $row = $this->db->raw(
            'SELECT GET_LOCK(?, 5) AS acquired',
            ['hotel-service-payment-' . (int) $reservationId]
        )->fetch(PDO::FETCH_ASSOC);

        return (int) ($row['acquired'] ?? 0) === 1;
    }

    public function releaseServicePaymentLock($reservationId)
    {
        $this->db->raw(
            'SELECT RELEASE_LOCK(?)',
            ['hotel-service-payment-' . (int) $reservationId]
        );
    }

    public function hasPendingServicePaymentForService($serviceRequestId)
    {
        $row = $this->db->raw(
            "SELECT p.id
             FROM reservation_payment_services ps
             INNER JOIN reservation_payments p ON p.id = ps.payment_id
             WHERE ps.reservation_service_id = ?
               AND p.kind = 'service' AND p.status = 'pending'
             LIMIT 1",
            [(int) $serviceRequestId]
        )->fetch(PDO::FETCH_ASSOC);

        return (bool) $row;
    }

    public function linkServiceItems($paymentId, array $items)
    {
        if (!$items) {
            return;
        }

        $records = array_map(
            static fn ($item) => [
                'payment_id'            => (int) $paymentId,
                'reservation_service_id'=> (int) $item['id'],
                'amount'                => (float) $item['total_amount'],
            ],
            $items
        );

        $linked = $this->db->table('reservation_payment_services')->bulk_insert($records);
        if (!$linked) {
            throw new \RuntimeException('Unable to link services to their payment.');
        }
    }

    public function markServiceItemsPaid($paymentId, $paidAt)
    {
        $this->db->raw(
            "UPDATE reservation_services rs
             INNER JOIN reservation_payment_services ps
                 ON ps.reservation_service_id = rs.id
             SET rs.billing_status = 'paid', rs.billed_at = ?
             WHERE ps.payment_id = ? AND rs.billing_status <> 'paid'",
            [$paidAt, (int) $paymentId]
        );
    }

    public function linkedServiceCount($paymentId)
    {
        $row = $this->db->raw(
            'SELECT COUNT(*) AS total
             FROM reservation_payment_services
             WHERE payment_id = ?',
            [(int) $paymentId]
        )->fetch(PDO::FETCH_ASSOC);

        return (int) ($row['total'] ?? 0);
    }

    public function recordPaid(array $payment, $paidAt)
    {
        if ($payment['kind'] === 'service' && $this->linkedServiceCount((int) $payment['id']) === 0) {
            throw new \RuntimeException('The service payment has no linked service items.');
        }

        $this->transaction();

        try {
            $updated = $this->update((int) $payment['id'], [
                'status'  => 'paid',
                'paid_at' => $paidAt,
            ]);
            if ($updated === false) {
                throw new \RuntimeException('Unable to record the payment.');
            }

            if ($payment['kind'] === 'service') {
                $this->markServiceItemsPaid((int) $payment['id'], $paidAt);
            }

            $this->commit();
        } catch (\Throwable $e) {
            $this->rollback();
            throw $e;
        }
    }

    public function syncPendingInvoices($reservationId, $externalId = '')
    {
        if ($externalId === '') {
            return;
        }

        $payments = $this->db->raw(
            "SELECT *
             FROM reservation_payments
             WHERE reservation_id = ?
               AND external_id = ?
               AND provider = 'xendit'
               AND status = 'pending'
               AND invoice_id <> ''
             LIMIT 1",
            [(int) $reservationId, (string) $externalId]
        )->fetchAll(PDO::FETCH_ASSOC);

        foreach ($payments as $payment) {

            [$code, $invoice] = $this->fetchInvoice($payment['invoice_id']);
            if ($code !== 200) {
                error_log('Could not verify pending Xendit payment ' . $payment['external_id'] . '.');
                continue;
            }

            if (
                (string) ($invoice['external_id'] ?? '') !== (string) $payment['external_id']
                || abs((float) ($invoice['amount'] ?? 0) - (float) $payment['amount']) > 0.01
            ) {
                error_log('Xendit invoice mismatch for ' . $payment['external_id'] . '.');
                continue;
            }

            $status = strtoupper((string) ($invoice['status'] ?? ''));
            if ($status === 'PAID' || $status === 'SETTLED') {
                $paidAt = $invoice['paid_at'] ?? null;
                $paidAtTimestamp = $paidAt ? strtotime($paidAt) : false;
                $this->recordPaid(
                    $payment,
                    $paidAtTimestamp
                        ? date('Y-m-d H:i:s', $paidAtTimestamp)
                        : date('Y-m-d H:i:s')
                );
            } elseif ($status === 'EXPIRED') {
                $this->update((int) $payment['id'], ['status' => 'expired']);
            }
        }
    }

    public function allForAdmin($status = '')
    {
        $sql = 'SELECT p.id, p.reservation_id, p.provider, p.kind, p.external_id,
                       p.amount, p.status, p.paid_at, p.created_at,
                       r.reservation_code, r.total_amount, r.status AS reservation_status,
                       r.check_in_date, r.check_out_date, rm.room_number,
                       c.name AS category_name, u.username AS guest_name, u.email AS guest_email
                FROM reservation_payments p
                INNER JOIN reservations r ON r.id = p.reservation_id
                INNER JOIN rooms rm ON rm.id = r.room_id
                INNER JOIN room_categories c ON c.id = rm.category_id
                INNER JOIN users u ON u.id = r.user_id';
        $params = [];

        if ($status !== '') {
            $sql .= ' WHERE p.status = ?';
            $params[] = $status;
        }

        $sql .= ' ORDER BY p.created_at DESC, p.id DESC';

        return $this->db->raw($sql, $params)->fetchAll(PDO::FETCH_ASSOC);
    }

    public function xenditRequest($method, $path, $body = null)
    {
        $key = $this->env('XENDIT_SECRET_KEY');

        if (!$key) {
            error_log('Xendit request skipped: XENDIT_SECRET_KEY is not set.');
            return [0, ['message' => 'Payments are not configured.']];
        }

        $ch = curl_init('https://api.xendit.co' . $path);

        $options = [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Basic ' . base64_encode($key . ':'),
                'Content-Type: application/json',
                'Accept: application/json',
            ],
        ];

        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = json_encode($body);
        }

        curl_setopt_array($ch, $options);

        $raw    = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error  = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            error_log('Xendit cURL error: ' . $error);
            return [0, ['message' => 'Could not reach the payment provider.']];
        }

        return [$status, json_decode($raw, true) ?: []];
    }

    public function createInvoice(array $data)
    {
        return $this->xenditRequest('POST', '/v2/invoices', $data);
    }

    public function fetchInvoice($invoiceId)
    {
        return $this->xenditRequest('GET', '/v2/invoices/' . rawurlencode((string) $invoiceId));
    }
}