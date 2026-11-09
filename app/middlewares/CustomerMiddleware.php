<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class CustomerMiddleware
{
    public function handle(Closure $next)
    {
        $lava = lava_instance();

        $lava->security_headers->apply_api();

        // Validate the access token, then load the current database user.
        $claims = $lava->api->require_jwt();

        $lava->call->model('UsersModel', 'usersModel');
        $user = $lava->usersModel->find((int) $claims['sub']);

        // Customer routes must never be available to administrative accounts.
        if (
            !$user ||
            !(int) $user['is_active'] ||
            $user['role'] !== 'customer'
        ) {
            $lava->api->respond_error('Customer access required.', 403);
        }

        return $next();
    }
}
