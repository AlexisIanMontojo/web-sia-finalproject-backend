<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class PasswordResetController extends Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->call->model('UsersModel', 'usersModel');
        $this->call->model('PasswordResetModel', 'resets');
    }

    private function sendResetEmail($email, $username, $token)
    {
        $apiKey    = getenv('RESEND_API_KEY');
        $fromEmail = getenv('RESEND_FROM_EMAIL') ?: 'onboarding@resend.dev';
        $fromName  = getenv('MAIL_FROM_NAME') ?: 'Hotel Reservation System';
        $frontend  = rtrim(getenv('FRONTEND_URL') ?: 'http://localhost:5173', '/');

        if (!$apiKey) {
            error_log('Password reset email skipped: RESEND_API_KEY is not set.');
            return false;
        }

        $link = $frontend . '/reset-password?token=' . urlencode($token);
        $safeName = htmlspecialchars($username, ENT_QUOTES, 'UTF-8');

        try {
            $resend = \Resend::client($apiKey);
            $resend->emails->send([
                'from'    => $fromName . ' <' . $fromEmail . '>',
                'to'      => [$email],
                'subject' => 'Reset your password',
                'html'    => "<p>Hello {$safeName},</p>"
                    . "<p>Use this link to choose a new password. It expires in 30 minutes.</p>"
                    . "<p><a href=\"{$link}\">Reset my password</a></p>"
                    . "<p>If you did not ask for this, ignore this email.</p>",
                'text'    => "Reset your password (expires in 30 minutes): {$link}",
            ]);
            return true;
        } catch (\Throwable $e) {
            error_log('Password reset email failed: ' . get_class($e) . ': ' . $e->getMessage());
            return false;
        }
    }

    public function forgot()
    {
        $this->api->require_method('POST');
        $this->api->rate_limit('forgot:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 5, 300);

        $in    = $this->api->body();
        $email = strtolower(trim((string) ($in['email'] ?? '')));

        // Same reply whether or not the account exists, so emails can't be probed.
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $user = $this->usersModel->findByEmail($email);

            if ($user && (int) $user['is_active'] && $user['role'] === 'customer') {
                $this->resets->invalidateFor((int) $user['id']);

                $token = bin2hex(random_bytes(32));

                $this->resets->insert([
                    'user_id'    => (int) $user['id'],
                    'token_hash' => hash('sha256', $token),
                    'expires_at' => gmdate('Y-m-d H:i:s', time() + 1800),
                    'used_at'    => null,
                ]);

                $this->sendResetEmail($user['email'], $user['username'], $token);
            }
        }

        $this->api->respond([
            'success' => true,
            'message' => 'If that email is registered, a reset link has been sent.',
        ]);
    }

    public function reset()
    {
        $this->api->require_method('POST');

        $in    = $this->api->body();
        $token = (string) ($in['token'] ?? '');

        if (!preg_match('/\A[a-f0-9]{64}\z/', $token)) {
            $this->api->respond_error('This reset link is invalid.', 400);
        }

        $_POST = $in;
        $this->call->library('form_validation');

        $valid = $this->form_validation->validate([
            'password|New password'     => 'required|min_length[8]|max_length[72]',
            'confirm|Confirm password'  => 'required|matches[password]',
        ]);

        if (!$valid) {
            $this->api->respond([
                'success' => false,
                'message' => 'Please correct the form.',
                'errors'  => $this->form_validation->get_errors(),
            ], 422);
        }

        $record = $this->resets->findValid(hash('sha256', $token));

        if (!$record) {
            $this->api->respond_error('This reset link is invalid or has expired.', 400);
        }

        $this->usersModel->setPassword(
            (int) $record['user_id'],
            password_hash((string) $in['password'], PASSWORD_DEFAULT)
        );
        $this->resets->markUsed((int) $record['id']);
        $this->usersModel->revokeSessions((int) $record['user_id']);

        $this->api->respond([
            'success' => true,
            'message' => 'Your password was changed. Please log in again.',
        ]);
    }
}