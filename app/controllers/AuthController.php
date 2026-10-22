
<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class AuthController extends Controller
{
    public function __construct()
    {
        parent::__construct();

        require_once APP_DIR . 'libraries/JwtHelper.php';

        $this->call->model('UsersModel', 'usersModel');
        $this->call->model(
            'EmailVerificationTokensModel',
            'verificationTokensModel'
        );
        $this->call->model('RefreshTokensModel', 'refreshTokensModel');
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
            $token = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $token);
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
                'Registration verification error: ' .
                get_class($e) . ': ' . $e->getMessage()
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

        if (!(int) ($user['is_active'] ?? 0)) {
            $this->respond(403, [
                'success' => false,
                'message' => 'This account is disabled.'
            ]);
        }

        $isAdmin = ($user['role'] ?? '') === 'admin';

                $isAdmin = ($user['role'] ?? '') === 'admin';

        // Each login page must only accept its own account type.
        $portal = ($data['portal'] ?? '') === 'admin' ? 'admin' : 'customer';

        if ($portal === 'admin' && !$isAdmin) {
            $this->respond(403, [
                'success' => false,
                'message' => 'This login is for administrators only.'
            ]);
        }

        if ($portal === 'customer' && $isAdmin) {
            $this->respond(403, [
                'success' => false,
                'message' => 'Administrators must sign in at the admin login page.'
            ]);
        }

        if (!$isAdmin && empty($user['email_verified_at'])) {
            $this->respond(403, [
                'success' => false,
                'message' => 'Please verify your email before logging in.'
            ]);
        }

        try {
            $accessToken = JwtHelper::issueAccessToken($user);
            $refreshToken = $this->issueRefreshToken((int) $user['id']);
        } catch (\Throwable $e) {
            error_log('Login session error: ' . get_class($e) . ': ' . $e->getMessage());

            $this->respond(500, [
                'success' => false,
                'message' => 'Unable to create a session. Please try again.'
            ]);
        }

        if (!$refreshToken) {
            $this->respond(500, [
                'success' => false,
                'message' => 'Unable to create a session. Please try again.'
            ]);
        }

        $this->setRefreshCookie($refreshToken);

        $this->respond(200, [
            'success' => true,
            'message' => 'Login successful.',
            'access_token' => $accessToken,
            'token_type' => 'Bearer',
            'expires_in' => JwtHelper::ACCESS_TTL,
            'user' => [
                'id' => (int) $user['id'],
                'username' => $user['username'],
                'email' => $user['email'],
                'role' => $user['role'],
                'email_verified' => !empty($user['email_verified_at'])
            ]
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

        if (
            !empty($verification['used_at']) ||
            strtotime($verification['expires_at'] ?? '') <= time()
        ) {
            $this->respond(400, [
                'success' => false,
                'message' => 'The verification link is expired or has already been used.'
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
            try {
                $this->verificationTokensModel->rollback();
            } catch (\Throwable $rollbackError) {
                error_log('Verification rollback failed.');
            }

            error_log(
                'Email verification error: ' .
                get_class($e) . ': ' . $e->getMessage()
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

        // Use the deployed frontend, not localhost.
        $frontendUrl = rtrim(
            getenv('FRONTEND_URL')
                ?: 'https://web-sia-finalproject-frontend-6cbb.vercel.app',
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
                       style="background:#174d38;color:#fff;padding:12px 20px;
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

            $resend->emails->send([
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
                'Verification email failed: ' .
                get_class($e) . ': ' . $e->getMessage()
            );

            return false;
        }
    }

        public function refresh()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->respond(405, [
                'success' => false,
                'message' => 'Method not allowed.'
            ]);
        }

        $raw = $this->readRefreshCookie();

        if (!$raw) {
            $this->clearRefreshCookie();
            $this->respond(401, [
                'success' => false,
                'message' => 'No active session.'
            ]);
        }

        $stored = $this->refreshTokensModel->findByHash(hash('sha256', $raw));

        // Revoked tokens are rejected, which also blocks replay of a rotated token.
        if (
            !$stored ||
            !empty($stored['revoked_at']) ||
            strtotime($stored['expires_at'] . ' UTC') <= time()
        ) {
            $this->clearRefreshCookie();
            $this->respond(401, [
                'success' => false,
                'message' => 'Session expired. Please log in again.'
            ]);
        }

        $user = $this->usersModel->find_by('id', (int) $stored['user_id']);

        if (!$user || !(int) $user['is_active']) {
            $this->refreshTokensModel->update((int) $stored['id'], [
                'revoked_at' => gmdate('Y-m-d H:i:s')
            ]);
            $this->clearRefreshCookie();
            $this->respond(403, [
                'success' => false,
                'message' => 'This account is disabled.'
            ]);
        }

        // Rotate: revoke the used token and issue a new one.
        $this->refreshTokensModel->update((int) $stored['id'], [
            'revoked_at' => gmdate('Y-m-d H:i:s')
        ]);

        $newRefresh = $this->issueRefreshToken((int) $user['id']);

        if (!$newRefresh) {
            $this->clearRefreshCookie();
            $this->respond(500, [
                'success' => false,
                'message' => 'Unable to refresh the session.'
            ]);
        }

        $this->setRefreshCookie($newRefresh);

        $this->respond(200, [
            'success' => true,
            'access_token' => JwtHelper::issueAccessToken($user),
            'token_type' => 'Bearer',
            'expires_in' => JwtHelper::ACCESS_TTL,
            'user' => [
                'id' => (int) $user['id'],
                'username' => $user['username'],
                'email' => $user['email'],
                'role' => $user['role'],
                'email_verified' => !empty($user['email_verified_at'])
            ]
        ]);
    }

    public function logout()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->respond(405, [
                'success' => false,
                'message' => 'Method not allowed.'
            ]);
        }

        $raw = $this->readRefreshCookie();

        if ($raw) {
            $stored = $this->refreshTokensModel->findByHash(hash('sha256', $raw));

            if ($stored && empty($stored['revoked_at'])) {
                $this->refreshTokensModel->update((int) $stored['id'], [
                    'revoked_at' => gmdate('Y-m-d H:i:s')
                ]);
            }
        }

        $this->clearRefreshCookie();

        $this->respond(200, [
            'success' => true,
            'message' => 'Logged out.'
        ]);
    }

    private function issueRefreshToken($userId)
    {
        $raw = bin2hex(random_bytes(32));

        $inserted = $this->refreshTokensModel->insert([
            'user_id' => $userId,
            'token_hash' => hash('sha256', $raw),
            'jti' => bin2hex(random_bytes(16)),
            'expires_at' => gmdate('Y-m-d H:i:s', time() + 60 * 60 * 24 * 7),
            'revoked_at' => null
        ]);

        return $inserted ? $raw : null;
    }

    private function setRefreshCookie($rawToken)
    {
        setcookie('refresh_token', $rawToken, [
            'expires' => time() + 60 * 60 * 24 * 7,
            'path' => '/api/auth',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
    }

    private function clearRefreshCookie()
    {
        setcookie('refresh_token', '', [
            'expires' => time() - 3600,
            'path' => '/api/auth',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
    }

    private function readRefreshCookie()
    {
        $token = $_COOKIE['refresh_token'] ?? '';

        return preg_match('/\A[a-f0-9]{64}\z/', $token) ? $token : null;
    }
}
