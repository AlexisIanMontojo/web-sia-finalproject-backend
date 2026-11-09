<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
/**
 * ------------------------------------------------------------------
 * LavaLust - an opensource lightweight PHP MVC Framework
 * ------------------------------------------------------------------
 *
 * Middleware aliases used by app/config/routes.php.
 * Each alias must resolve to an instantiated middleware class.
 */

$config['middlewares'] = array(
    'admin'    => load_class('AdminMiddleware', 'middlewares'),
    'manager'  => load_class('ManagerMiddleware', 'middlewares'),
    'staff'    => load_class('StaffMiddleware', 'middlewares'),
    'customer' => load_class('CustomerMiddleware', 'middlewares'),
);
