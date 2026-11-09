<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class ManagerMiddleware
{
    public function handle(Closure $next)
    {
        $lava = lava_instance();

        $lava->security_headers->apply_api();

        // Validate the access token, then load the current database user.
        $claims = $lava->api->require_jwt();

        $lava->call->model('UsersModel', 'usersModel');
        $user = $lava->usersModel->find((int) $claims['sub']);

        // Managers and administrators may access manager-level routes.
        $allowedRoles = ['admin', 'manager'];

        if (
            !$user ||
            !(int) $user['is_active'] ||
            !in_array($user['role'], $allowedRoles, true)
        ) {
            $lava->api->respond_error('Manager access required.', 403);
        }

        return $next();
    }
}
