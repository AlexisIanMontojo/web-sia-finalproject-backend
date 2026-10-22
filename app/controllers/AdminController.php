<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class AdminController extends Controller
{
    public function __construct()
    {
        parent::__construct();

        require_once APP_DIR . 'libraries/JwtHelper.php';
        $this->call->model('UsersModel', 'usersModel');
    }

    private function respond($status, $data)
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data);
        exit;
    }

    /**
     * Verifies the bearer token, then re-checks the database so a
     * disabled account or a demoted role loses access immediately.
     */
    private function requireAdmin()
    {
        $token = JwtHelper::bearerToken();

        if (!$token) {
            $this->respond(401, [
                'success' => false,
                'message' => 'Authentication required.'
            ]);
        }

        $claims = JwtHelper::decodeAccessToken($token);

        if (!$claims || empty($claims['sub'])) {
            $this->respond(401, [
                'success' => false,
                'message' => 'Invalid or expired token.'
            ]);
        }

        $user = $this->usersModel->find_by('id', (int) $claims['sub']);

        if (!$user || !(int) $user['is_active'] || $user['role'] !== 'admin') {
            $this->respond(403, [
                'success' => false,
                'message' => 'Administrator access required.'
            ]);
        }

        return $user;
    }

    public function me()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            $this->respond(405, [
                'success' => false,
                'message' => 'Method not allowed.'
            ]);
        }

        $user = $this->requireAdmin();

        $this->respond(200, [
            'success' => true,
            'user' => [
                'id' => (int) $user['id'],
                'username' => $user['username'],
                'email' => $user['email'],
                'role' => $user['role']
            ]
        ]);
    }
}