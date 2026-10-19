<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class AuthController extends Controller
{
    public function __construct()
    {
        parent::__construct();

        $this->call->model('UsersModel', 'usersModel');
        $this->call->model(
            'EmailVerificationTokensModel',
            'verificationTokensModel'
        );
    }

    private function respond($status, $data)
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');

        echo json_encode($data);
        exit;
    }

    private function getJsonInput()
    {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true);

        return is_array($data) ? $data : [];
    }

    public function register()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->respond(405, [
                'success' => false,
                'message' => 'Method not allowed.'
            ]);
        }

        $data = $this->getJsonInput();

        $username = trim($data['username'] ?? '');
        $email = strtolower(trim($data['email'] ?? ''));
        $password = $data['password'] ?? '';

        if (
            $username === '' ||
            strlen($username) > 100 ||
            !filter_var($email, FILTER_VALIDATE_EMAIL) ||
            strlen($email) > 255 ||
            !is_string($password) ||
            strlen($password) < 8 ||
            strlen($password) > 72
        ) {
            $this->respond(422, [
                'success' => false,
                'message' => 'Provide a valid username, email, and password of 8-72 characters.'
            ]);
        }

        if (
            $this->usersModel->findByEmail($email) ||
            $this->usersModel->findByUsername($username)
        ) {
            $this->respond(409, [
                'success' => false,
                'message' => 'The username or email is already registered.'
            ]);
        }

        $userId = $this->usersModel->createCustomer([
            'username' => $username,
            'email' => $email,
            'password' => password_hash($password, PASSWORD_DEFAULT),
            'role' => 'customer',
            'is_active' => 1,
            'email_verified_at' => null
        ]);

        if (!$userId) {
            $this->respond(500, [
                'success' => false,
                'message' => 'Unable to create the account.'
            ]);
        }

        try {
            // Generate a secure, random verification token.
            $token = bin2hex(random_bytes(32));

            // Store only the token hash in the database.
            $tokenHash = hash('sha256', $token);

            // Token expires in 30 minutes.
            $expiresAt = gmdate('Y-m-d H:i:s', time() + 1800);

            $tokenId = $this->verificationTokensModel->createToken([
                'user_id' => $userId,
                'token_hash' => $tokenHash,
                'expires_at' => $expiresAt,
                'used_at' => null
            ]);

            if (!$tokenId) {
                error_log('Failed to store email verification token.');

                $this->respond(500, [
                    'success' => false,
                    'account_created' => true,
                    'message' => 'The account was created, but its verification token could not be stored.'
                ]);
            }

            $emailSent = $this->sendVerificationEmail(
                $email,
                $username,
                $token
            );

            if (!$emailSent) {
                $this->respond(503, [
                    'success' => false,
                    'account_created' => true,
                    'message' => 'The account was created, but the verification email could not be sent. Check the backend logs.'
                ]);
            }
        } catch (\Throwable $e) {
            error_log(
                'Registration verification error: '
                . get_class($e) . ': ' . $e->getMessage()
            );

            $this->respond(500, [
                'success' => false,
                'account_created' => true,
                'message' => 'The account was created, but verification setup failed.'
            ]);
        }

        $this->respond(201, [
            'success' => true,
            'message' => 'Account created. Check your email to verify your account.',
            'email_sent' => true,
            'user' => [
                'id' => $userId,
                'username' => $username,
                'email' => $email,
                'role' => 'customer',
                'email_verified' => false
            ]
        ]);
    }

    public function login()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->respond(405, [
                'success' => false,
                'message' => 'Method not allowed.'
            ]);
        }

        $data = $this->getJsonInput();

        $email = strtolower(trim($data['email'] ?? ''));
        $password = $data['password'] ?? '';

        if (
            !filter_var($email, FILTER_VALIDATE_EMAIL) ||
            !is_string($password) ||
            $password === ''
        ) {
            $this->respond(422, [
                'success' => false,
                'message' => 'A valid email and password are required.'
            ]);
        }

        $user = $this->usersModel->findByEmail($email);

        if (
            !$user ||
            !is_string($user['password'] ?? null) ||
            !password_verify($password, $user['password'])
        ) {
            $this->respond(401, [
                'success' => false,
                'message' => 'Invalid email or password.'
            ]);
        }

        if (!(int) $user['is_active']) {
            $this->respond(403, [
                'success' => false,
                'message' => 'This account is disabled.'
            ]);
        }

        if (empty($user['email_verified_at'])) {
            $this->respond(403, [
                'success' => false,
                'message' => 'Please verify your email before logging in.'
            ]);
        }

        // JWT and refresh-token issuance are not implemented yet.
        $this->respond(200, [
            'success' => true,
            'message' => 'Credentials verified, but token issuance is not implemented yet.'
        ]);
    }

    public function verifyEmail()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->respond(405, [
                'success' => false,
                'message' => 'Method not allowed.'
            ]);
        }

        $data = $this->getJsonInput();
        $token = $data['token'] ?? '';

        if (
            !is_string($token) ||
            !preg_match('/\A[a-f0-9]{64}\z/', $token)
        ) {
            $this->respond(422, [
                'success' => false,
                'message' => 'A valid verification token is required.'
            ]);
        }

        $tokenHash = hash('sha256', $token);

        $verification = $this->verificationTokensModel
            ->findByHash($tokenHash);

        if (!$verification) {
            $this->respond(400, [
                'success' => false,
                'message' => 'The verification link is invalid or expired.'
            ]);
        }

        if (!empty($verification['used_at'])) {
            $this->respond(400, [
                'success' => false,
                'message' => 'This verification link has already been used.'
            ]);
        }

        try {
            $this->verificationTokensModel->transaction();

            $statement = $this->db->raw(
                'UPDATE email_verification_tokens
                 SET used_at = UTC_TIMESTAMP()
                 WHERE id = ?
                   AND used_at IS NULL
                   AND expires_at > UTC_TIMESTAMP()',
                [(int) $verification['id']]
            );

            if ($statement->rowCount() !== 1) {
                $this->verificationTokensModel->rollback();

                $this->respond(400, [
                    'success' => false,
                    'message' => 'The verification link is invalid, expired, or already used.'
                ]);
            }

            $updated = $this->usersModel->update(
                (int) $verification['user_id'],
                ['email_verified_at' => gmdate('Y-m-d H:i:s')]
            );

            if ($updated === false) {
                $this->verificationTokensModel->rollback();

                $this->respond(500, [
                    'success' => false,
                    'message' => 'Unable to verify the account. Please try again.'
                ]);
            }

            $this->verificationTokensModel->commit();

            $this->respond(200, [
                'success' => true,
                'message' => 'Email verified successfully. You can now log in.',
                'email_verified' => true
            ]);
        } catch (\Throwable $e) {
            $this->verificationTokensModel->rollback();

            error_log(
                'Email verification error: '
                . get_class($e) . ': ' . $e->getMessage()
            );

            $this->respond(500, [
                'success' => false,
                'message' => 'An error occurred while verifying your email.'
            ]);
        }
    }

    private function sendVerificationEmail($email, $username, $token)
    {
        $apiKey = getenv('RESEND_API_KEY');
        $fromEmail = getenv('RESEND_FROM_EMAIL') ?: 'onboarding@resend.dev';
        $fromName = getenv('MAIL_FROM_NAME') ?: 'Hotel Reservation System';

        $frontendUrl = rtrim(
            getenv('FRONTEND_URL') ?: 'https://web-sia-finalproject-frontend.vercel.app',
            '/'
        );

        if (!$apiKey || !$fromEmail || !$email || !$token) {
            error_log(
                'Verification email failed: Required configuration is missing.'
            );
            return false;
        }

        $verificationUrl = $frontendUrl
            . '/verify-email?token=' . urlencode($token);

        $safeUsername = htmlspecialchars(
            $username,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );

        $safeVerificationUrl = htmlspecialchars(
            $verificationUrl,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );

        $html = '
            <div style="font-family:Arial,sans-serif;max-width:600px;margin:auto">
                <h2>Welcome to our Hotel Reservation System!</h2>
                <p>Hello ' . $safeUsername . ',</p>
                <p>Thank you for registering. Click below to verify your email.</p>
                <p>
                    <a href="' . $safeVerificationUrl . '"
                       style="background:#2563eb;color:#fff;padding:12px 20px;
                       text-decoration:none;border-radius:5px;display:inline-block">
                        Verify My Email
                    </a>
                </p>
                <p>This link expires in 30 minutes and can only be used once.</p>
                <p>If you did not create this account, you can ignore this email.</p>
            </div>
        ';

        $plainText = "Hello {$username}, verify your email using this link: "
            . $verificationUrl
            . "\nThis link expires in 30 minutes.";

        try {
            if (!class_exists('\Resend')) {
                throw new \RuntimeException(
                    'Resend SDK is not loaded. Check Composer autoloading.'
                );
            }

            $resend = \Resend::client($apiKey);

            $result = $resend->emails->send([
                'from' => $fromName . ' <' . $fromEmail . '>',
                'to' => [$email],
                'subject' => 'Verify Your Hotel Reservation Account',
                'html' => $html,
                'text' => $plainText
            ]);

            error_log('Verification email request accepted by Resend.');

            return true;
        } catch (\Throwable $e) {
            error_log(
                'Verification email failed: '
                . get_class($e) . ': ' . $e->getMessage()
            );

            return false;
        }
    }
}
