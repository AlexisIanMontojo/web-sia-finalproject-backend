<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class ManagerMiddleware
{
    public function handle(Closure $next)
    {
        $lava = lava_instance();

        $lava->security_headers->apply_api();

        $claims = $lava->api->require_jwt();

        $lava->call->model('UsersModel', 'usersModel');
        $user = $lava->usersModel->find((int) $claims['sub']);

        $allowed = ['admin', 'manager'];

        if (!$user || !(int) $user['is_active'] || !in_array($user['role'], $allowed, true)) {
            $lava->api->respond_error('You do not have permission to do this.', 403);
        }

        return $next();
    }
}