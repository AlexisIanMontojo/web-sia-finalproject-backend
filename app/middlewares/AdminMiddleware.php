<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class AdminMiddleware
{
    public function handle(Closure $next)
    {
        $lava = lava_instance();

        $lava->security_headers->apply_api();

        // Answers 401 itself when the token is missing or invalid.
        $claims = $lava->api->require_jwt();

        // Read the role from the database, so a demoted or disabled user loses access at once.
        $lava->call->model('UsersModel', 'usersModel');
        $user = $lava->usersModel->find((int) $claims['sub']);

        if (!$user || !(int) $user['is_active'] || $user['role'] !== 'admin') {
            $lava->api->respond_error('You do not have permission to do this.', 403);
        }

        return $next();
    }
}