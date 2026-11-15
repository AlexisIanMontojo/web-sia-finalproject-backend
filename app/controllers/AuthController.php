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

    public function googleSignup()
    {
        $this->security_headers->apply_api();
        $this->api->require_method('POST');
        $this->api->rate_limit('google-signup:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 10, 60);

        $clientId = getenv('GOOGLE_CLIENT_ID');
        if (!$clientId) {
            $this->api->respond_error('Google sign-up is not configured on the server.', 503);
        }

        $in = $this->api->body();
        $credential = $in['credential'] ?? '';
        if (!is_string($credential) || $credential === '' || strlen($credential) > 8192) {
            $this->api->respond_error('A valid Google credential is required.', 422);
        }

        $claims = $this->verifyGoogleCredential($credential, $clientId);
        if (!$claims) {
            $this->api->respond_error('Google could not verify this account. Please try again.', 401);
        }

        $email = strtolower(trim($claims['email']));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) {
            $this->api->respond_error('Google did not provide a valid email address.', 401);
        }

        $user = $this->usersModel->findByEmail($email);
        $accountCreated = false;

        if ($user) {
            if ($user['role'] !== 'customer') {
                $this->api->respond_error('This email is not available for customer sign-up.', 403);
            }
            if (!(int) $user['is_active']) {
                $this->api->respond_error('This account is disabled.', 403);
            }
        } else {
            $username = $this->availableGoogleUsername(
                (string) ($claims['name'] ?? ''),
                $email
            );
            $userId = $this->usersModel->createCustomer([
                'username'          => $username,
                'email'             => $email,
                'password'          => password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),
                'role'              => 'customer',
                'is_active'         => 1,
                'email_verified_at' => null,
            ]);

            if (!$userId) {
                $this->api->respond_error('Unable to create the account.', 500);
            }

            $user = [
                'id'       => (int) $userId,
                'username' => $username,
                'email'    => $email,
                'role'     => 'customer',
                'is_active'=> 1,
            ];
            $accountCreated = true;
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $codeHash = hash_hmac(
            'sha256',
            (int) $user['id'] . ':' . $code,
            (string) getenv('JWT_SECRET')
        );

        try {
            $this->verificationTokensModel->invalidateFor((int) $user['id']);
            $tokenId = $this->verificationTokensModel->insert([
                'user_id'    => (int) $user['id'],
                'token_hash' => $codeHash,
                'expires_at' => gmdate('Y-m-d H:i:s', time() + 600),
                'used_at'    => null,
            ]);
        } catch (\Throwable $e) {
            error_log('Google signup verification setup failed: ' . get_class($e) . ': ' . $e->getMessage());
            $this->api->respond_error('Unable to prepare email verification. Please try again.', 500);
        }

        if (!$tokenId) {
            $this->api->respond_error('Unable to prepare email verification. Please try again.', 500);
        }

        if (!$this->sendVerificationCodeEmail($email, $user['username'], $code)) {
            $this->api->respond([
                'success'         => false,
                'account_created' => $accountCreated,
                'message'         => 'Your account is ready, but the verification code could not be sent. Please try Google sign-up again.',
            ], 503);
        }

        $this->api->respond([
            'success'              => true,
            'verification_required'=> true,
            'email'                => $email,
            'message'              => 'A 6-digit verification code has been sent to your email.',
        ], $accountCreated ? 201 : 200);
    }

    public function verifyEmailCode()
    {
        $this->security_headers->apply_api();
        $this->api->require_method('POST');
        $this->api->rate_limit('verify-email-code:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 10, 300);

        $in    = $this->api->body();
        $email = strtolower(trim((string) ($in['email'] ?? '')));
        $code  = $in['code'] ?? '';

        if (
            !filter_var($email, FILTER_VALIDATE_EMAIL) ||
            !is_string($code) ||
            !preg_match('/\A[0-9]{6}\z/', $code)
        ) {
            $this->api->respond_error('Enter a valid email and 6-digit verification code.', 422);
        }

        $user = $this->usersModel->findByEmail($email);
        if (!$user || $user['role'] !== 'customer' || !(int) $user['is_active']) {
            $this->api->respond_error('The verification code is invalid or expired.', 400);
        }

        $codeHash = hash_hmac(
            'sha256',
            (int) $user['id'] . ':' . $code,
            (string) getenv('JWT_SECRET')
        );
        $record = $this->verificationTokensModel->find_by('token_hash', $codeHash);

        if (
            !$record ||
            (int) $record['user_id'] !== (int) $user['id'] ||
            !empty($record['used_at']) ||
            strtotime($record['expires_at'] . ' UTC') <= time()
        ) {
            $this->api->respond_error('The verification code is invalid or expired.', 400);
        }

        try {
            $this->verificationTokensModel->transaction();
            $this->verificationTokensModel->update((int) $record['id'], [
                'used_at' => gmdate('Y-m-d H:i:s'),
            ]);
            $this->usersModel->update((int) $user['id'], [
                'email_verified_at' => gmdate('Y-m-d H:i:s'),
            ]);
            $this->verificationTokensModel->commit();
        } catch (\Throwable $e) {
            $this->verificationTokensModel->rollback();
            error_log('Email code verification error: ' . get_class($e) . ': ' . $e->getMessage());
            $this->api->respond_error('An error occurred while verifying your email.', 500);
        }

        $tokens = $this->api->issue_tokens([
            'id'   => (int) $user['id'],
            'role' => $user['role'],
        ]);

        $this->api->respond([
            'success'       => true,
            'message'       => 'Email verified successfully.',
            'access_token'  => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'],
            'token_type'    => $tokens['token_type'],
            'expires_in'    => $tokens['expires_in'],
            'user'          => [
                'id'             => (int) $user['id'],
                'username'       => $user['username'],
                'email'          => $user['email'],
                'role'           => $user['role'],
                'email_verified' => true,
            ],
        ]);
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

        $isStaff = $user['role'] !== 'customer';

        if ($portal === 'admin' && !$isStaff) {
            $this->api->respond_error('This login is for staff accounts only.', 403);
        }

        if ($portal === 'customer' && $isStaff) {
            $this->api->respond_error('Staff must sign in at the staff login page.', 403);
        }

        if (!$isStaff && empty($user['email_verified_at'])) {
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
        $frontendUrl = rtrim(
            getenv('FRONTEND_URL') ?: 'http://localhost:5173',
            '/'
        );

        if (!$email || !$token) {
            error_log('Verification email failed: Missing recipient or token.');
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

        return $this->sendAuthEmail(
            $email,
            $username,
            'Verify Your Hotel Reservation Account',
            $html,
            $plainText
        );
    }

    private function sendVerificationCodeEmail($email, $username, $code)
    {
        $safeUsername = htmlspecialchars($username, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeCode = htmlspecialchars($code, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $html = '
            <div style="font-family:Arial,sans-serif;max-width:600px;margin:auto">
                <h2>Verify your hotel reservation account</h2>
                <p>Hello ' . $safeUsername . ',</p>
                <p>Enter this verification code to finish signing up with Google:</p>
                <p style="font-size:28px;font-weight:bold;letter-spacing:8px">'
                    . $safeCode .
                '</p>
                <p>This code expires in 10 minutes and can only be used once.</p>
            </div>
        ';
        $plainText = "Hello {$username},\n\n"
            . "Your hotel reservation verification code is: {$code}\n\n"
            . "This code expires in 10 minutes and can only be used once.";

        return $this->sendAuthEmail(
            $email,
            $username,
            'Your Hotel Reservation Verification Code',
            $html,
            $plainText
        );
    }

    private function sendAuthEmail($email, $username, $subject, $html, $plainText)
    {
        $smtpHost = getenv('MAIL_HOST') ?: 'smtp.gmail.com';
        $smtpPort = (int) (getenv('MAIL_PORT') ?: 587);
        $smtpUser = getenv('MAIL_USERNAME');
        $smtpPass = getenv('MAIL_PASSWORD');
        $fromEmail = getenv('MAIL_FROM_EMAIL') ?: $smtpUser;
        $fromName = getenv('MAIL_FROM_NAME') ?: 'Hotel Reservation System';

        if (!$smtpUser || !$smtpPass || !$fromEmail || !$email) {
            error_log('Authentication email failed: Missing SMTP configuration.');
            return false;
        }

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
            $mail->Subject = $subject;
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

    private function verifyGoogleCredential($credential, $clientId)
    {
        if (!function_exists('curl_init')) {
            $this->api->respond_error('Google sign-up is unavailable on the server.', 503);
        }

        $url = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . rawurlencode($credential);
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $response = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);

        if ($response === false) {
            $error = curl_error($curl);
            curl_close($curl);
            error_log('Google credential verification request failed: ' . $error);
            $this->api->respond_error('Google sign-up is temporarily unavailable. Please try again.', 502);
        }
        curl_close($curl);

        if ($status === 400 || $status === 401) {
            return null;
        }
        if ($status !== 200) {
            error_log('Google credential verification returned HTTP ' . $status . '.');
            $this->api->respond_error('Google sign-up is temporarily unavailable. Please try again.', 502);
        }

        $claims = json_decode($response, true);
        if (
            !is_array($claims) ||
            !isset($claims['sub'], $claims['email'], $claims['aud'], $claims['iss'], $claims['exp'], $claims['email_verified']) ||
            !is_string($claims['sub']) ||
            $claims['sub'] === '' ||
            !is_string($claims['email']) ||
            !is_string($claims['aud']) ||
            !hash_equals($clientId, $claims['aud']) ||
            !in_array($claims['iss'], ['accounts.google.com', 'https://accounts.google.com'], true) ||
            !in_array($claims['email_verified'], [true, 'true'], true) ||
            !is_numeric($claims['exp']) ||
            (int) $claims['exp'] <= time()
        ) {
            return null;
        }

        return $claims;
    }

    private function availableGoogleUsername($name, $email)
    {
        $username = trim($name);
        if ($username === '') {
            $username = strstr($email, '@', true) ?: 'Google user';
        }
        $username = mb_substr($username, 0, 100);
        if (!$this->usersModel->findByUsername($username)) {
            return $username;
        }

        for ($suffix = 2; $suffix < 10000; $suffix++) {
            $ending = ' ' . $suffix;
            $candidate = mb_substr($username, 0, 100 - mb_strlen($ending)) . $ending;
            if (!$this->usersModel->findByUsername($candidate)) {
                return $candidate;
            }
        }

        throw new \RuntimeException('Unable to create a unique Google account username.');
    }
}