<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class AuthController extends Controller
{
    public function __construct()
    {
        parent::__construct();

        $this->call->model('UsersModel', 'usersModel');
        $this->call->model('EmailVerificationTokensModel', 'verificationTokensModel');
    }

    public function register()
    {
        $this->security_headers->apply_api();
        $this->api->require_method('POST');

        $in       = $this->api->body();
        $username = trim((string) ($in['username'] ?? ''));
        $email    = strtolower(trim((string) ($in['email'] ?? '')));
        $password = $in['password'] ?? '';

        if (
            $username === '' ||
            mb_strlen($username) > 100 ||
            !filter_var($email, FILTER_VALIDATE_EMAIL) ||
            strlen($email) > 255 ||
            !is_string($password) ||
            strlen($password) < 8 ||
            strlen($password) > 72
        ) {
            $this->api->respond_error(
                'Provide a valid username, email, and password of 8-72 characters.',
                422
            );
        }

        if ($this->usersModel->findByEmail($email) || $this->usersModel->findByUsername($username)) {
            $this->api->respond_error('The username or email is already registered.', 409);
        }

        $userId = $this->usersModel->createCustomer([
            'username'          => $username,
            'email'             => $email,
            'password'          => password_hash($password, PASSWORD_DEFAULT),
            'role'              => 'customer',
            'is_active'         => 1,
            'email_verified_at' => null,
        ]);

        if (!$userId) {
            $this->api->respond_error('Unable to create the account.', 500);
        }

        try {
            $token = bin2hex(random_bytes(32));

            $tokenId = $this->verificationTokensModel->insert([
                'user_id'    => (int) $userId,
                'token_hash' => hash('sha256', $token),
                'expires_at' => gmdate('Y-m-d H:i:s', time() + 1800),
                'used_at'    => null,
            ]);

            if (!$tokenId) {
                error_log('Failed to store email verification token.');
                $this->api->respond([
                    'success'         => false,
                    'account_created' => true,
                    'message'         => 'The account was created, but its verification token could not be stored.',
                ], 500);
            }

            if (!$this->sendVerificationEmail($email, $username, $token)) {
                $this->api->respond([
                    'success'         => false,
                    'account_created' => true,
                    'message'         => 'The account was created, but the verification email could not be sent.',
                ], 503);
            }
        } catch (\Throwable $e) {
            error_log('Registration verification error: ' . get_class($e) . ': ' . $e->getMessage());
            $this->api->respond([
                'success'         => false,
                'account_created' => true,
                'message'         => 'The account was created, but verification setup failed.',
            ], 500);
        }

        $this->api->respond([
            'success'    => true,
            'message'    => 'Account created. Check your email to verify your account.',
            'email_sent' => true,
            'user'       => [
                'id'             => (int) $userId,
                'username'       => $username,
                'email'          => $email,
                'role'           => 'customer',
                'email_verified' => false,
            ],
        ], 201);
    }

    public function login()
    {
        $this->security_headers->apply_api();
        $this->api->require_method('POST');

        // 10 attempts per minute per IP slows down password guessing.
        $this->api->rate_limit('login:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 10, 60);

        $in       = $this->api->body();
        $email    = strtolower(trim((string) ($in['email'] ?? '')));
        $password = (string) ($in['password'] ?? '');
        $portal   = ($in['portal'] ?? '') === 'admin' ? 'admin' : 'customer';

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
            $this->api->respond_error('A valid email and password are required.', 422);
        }

        $user = $this->usersModel->findByEmail($email);

        if (!$user || !password_verify($password, $user['password'])) {
            $this->api->respond_error('Invalid email or password.', 401);
        }

        if (!(int) $user['is_active']) {
            $this->api->respond_error('This account is disabled.', 403);
        }

        $isAdmin = $user['role'] === 'admin';

        if ($portal === 'admin' && !$isAdmin) {
            $this->api->respond_error('This login is for administrators only.', 403);
        }

        if ($portal === 'customer' && $isAdmin) {
            $this->api->respond_error('Administrators must sign in at the admin login page.', 403);
        }

        if (!$isAdmin && empty($user['email_verified_at'])) {
            $this->api->respond_error('Please verify your email before logging in.', 403);
        }

        $tokens = $this->api->issue_tokens([
            'id'   => (int) $user['id'],
            'role' => $user['role'],
        ]);

        $this->api->respond([
            'success'       => true,
            'message'       => 'Login successful.',
            'access_token'  => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'],
            'token_type'    => $tokens['token_type'],
            'expires_in'    => $tokens['expires_in'],
            'user'          => [
                'id'             => (int) $user['id'],
                'username'       => $user['username'],
                'email'          => $user['email'],
                'role'           => $user['role'],
                'email_verified' => !empty($user['email_verified_at']),
            ],
        ]);
    }

    public function refresh()
    {
        $this->security_headers->apply_api();
        $this->api->require_method('POST');

        $in = $this->api->body();

        // The library validates the token, rotates it, and sends the response.
        $this->api->refresh_access_token((string) ($in['refresh_token'] ?? ''));
    }

    public function logout()
    {
        $this->security_headers->apply_api();
        $this->api->require_method('POST');

        $in = $this->api->body();
        $this->api->revoke_refresh_token((string) ($in['refresh_token'] ?? ''));

        $this->api->respond(['success' => true, 'message' => 'Logged out.']);
    }

    public function me()
    {
        $this->security_headers->apply_api();
        $this->api->require_method('GET');

        $claims = $this->api->require_jwt();
        $user   = $this->usersModel->find((int) $claims['sub']);

        if (!$user || !(int) $user['is_active']) {
            $this->api->respond_error('Account unavailable.', 403);
        }

        $this->api->respond([
            'success' => true,
            'user'    => [
                'id'             => (int) $user['id'],
                'username'       => $user['username'],
                'email'          => $user['email'],
                'role'           => $user['role'],
                'email_verified' => !empty($user['email_verified_at']),
            ],
        ]);
    }

    public function verifyEmail()
    {
        $this->security_headers->apply_api();
        $this->api->require_method('POST');

        $in    = $this->api->body();
        $token = $in['token'] ?? '';

        if (!is_string($token) || !preg_match('/\A[a-f0-9]{64}\z/', $token)) {
            $this->api->respond_error('A valid verification token is required.', 422);
        }

        $record = $this->verificationTokensModel->find_by('token_hash', hash('sha256', $token));

        if (!$record) {
            $this->api->respond_error('The verification link is invalid or expired.', 400);
        }

        if (
            !empty($record['used_at']) ||
            strtotime($record['expires_at'] . ' UTC') <= time()
        ) {
            $this->api->respond_error('The verification link is expired or has already been used.', 400);
        }

        try {
            $this->verificationTokensModel->transaction();

            $this->verificationTokensModel->update((int) $record['id'], [
                'used_at' => gmdate('Y-m-d H:i:s'),
            ]);

            $this->usersModel->update((int) $record['user_id'], [
                'email_verified_at' => gmdate('Y-m-d H:i:s'),
            ]);

            $this->verificationTokensModel->commit();
        } catch (\Throwable $e) {
            $this->verificationTokensModel->rollback();
            error_log('Email verification error: ' . get_class($e) . ': ' . $e->getMessage());
            $this->api->respond_error('An error occurred while verifying your email.', 500);
        }

        $this->api->respond([
            'success'        => true,
            'message'        => 'Email verified successfully. You can now log in.',
            'email_verified' => true,
        ]);
    }

    private function sendVerificationEmail($email, $username, $token)
    {
        $smtpHost = getenv('MAIL_HOST') ?: 'smtp.gmail.com';
        $smtpPort = (int) (getenv('MAIL_PORT') ?: 587);
        $smtpUser = getenv('MAIL_USERNAME');
        $smtpPass = getenv('MAIL_PASSWORD');

        $fromEmail = getenv('MAIL_FROM_EMAIL') ?: $smtpUser;
        $fromName = getenv('MAIL_FROM_NAME') ?: 'Hotel Reservation System';

        $frontendUrl = rtrim(
            getenv('FRONTEND_URL') ?: 'http://localhost:5173',
            '/'
        );

        if (
            !$smtpUser ||
            !$smtpPass ||
            !$fromEmail ||
            !$email ||
            !$token
        ) {
            error_log('Verification email failed: Missing SMTP configuration.');
            return false;
        }

        $verificationUrl = $frontendUrl
            . '/verify-email?token='
            . urlencode($token);

        $safeUsername = htmlspecialchars(
            $username,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );

        $safeUrl = htmlspecialchars(
            $verificationUrl,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );

        $html = '
            <div style="font-family:Arial,sans-serif;max-width:600px;margin:auto">
                <h2>Welcome to Our Hotel Reservation System!</h2>

                <p>Hello ' . $safeUsername . ',</p>

                <p>
                    Thank you for registering. Please verify your email
                    address to activate your customer account.
                </p>

                <p style="margin:30px 0">
                    <a href="' . $safeUrl . '"
                    style="background:#174d38;color:#fff;padding:12px 20px;
                    text-decoration:none;border-radius:5px;display:inline-block">
                        Verify My Email
                    </a>
                </p>

                <p>This link expires in 30 minutes and can only be used once.</p>

                <p>If you did not create this account, you can ignore this email.</p>
            </div>
        ';

        $plainText = "Hello {$username},\n\n"
            . "Please verify your hotel reservation account using this link:\n"
            . $verificationUrl
            . "\n\nThis link expires in 30 minutes.";

        try {
            if (!class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
                throw new \RuntimeException(
                    'PHPMailer is not loaded. Check Composer autoloading.'
                );
            }

            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

            $mail->isSMTP();
            $mail->Host = $smtpHost;
            $mail->SMTPAuth = true;
            $mail->Username = $smtpUser;
            $mail->Password = $smtpPass;
            $mail->SMTPSecure =
                \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = $smtpPort;

            $mail->CharSet = 'UTF-8';

            $mail->setFrom($fromEmail, $fromName);
            $mail->addAddress($email, $username);

            $mail->isHTML(true);
            $mail->Subject = 'Verify Your Hotel Reservation Account';
            $mail->Body = $html;
            $mail->AltBody = $plainText;

            $mail->send();

            return true;
        } catch (\Throwable $e) {
            error_log(
                'Verification email failed: '
                . get_class($e)
                . ': '
                . $e->getMessage()
            );

            return false;
        }
    }
}