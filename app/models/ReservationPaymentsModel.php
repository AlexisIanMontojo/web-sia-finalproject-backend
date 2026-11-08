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

    public function paidTotal($reservationId)
    {
        $row = $this->db->raw(
            "SELECT COALESCE(SUM(amount), 0) AS total
             FROM reservation_payments
             WHERE reservation_id = ? AND status = 'paid'",
            [(int) $reservationId]
        )->fetch(PDO::FETCH_ASSOC);

        return (float) ($row['total'] ?? 0);
    }

    /** Sends a request to Xendit. Returns [HTTP status, decoded body]. */
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