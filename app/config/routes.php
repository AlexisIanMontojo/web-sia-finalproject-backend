<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
/**
 * ------------------------------------------------------------------
 * LavaLust - an opensource lightweight PHP MVC Framework
 * ------------------------------------------------------------------
 *
 * MIT License
 *
 * Copyright (c) 2020 Ronald M. Marasigan
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 *
 * @package LavaLust
 * @author Ronald M. Marasigan <ronald.marasigan@yahoo.com>
 * @since Version 1
 * @link https://github.com/ronmarasigan/LavaLust
 * @license https://opensource.org/licenses/MIT MIT License
 */

/*
| -------------------------------------------------------------------
| URI ROUTING
| -------------------------------------------------------------------
| Here is where you can register web routes for your application.
|
|
*/
/** @var object $router **/

// Authentication API
$router->post('/api/auth/register', 'AuthController::register');
$router->post('/api/auth/login', 'AuthController::login');
$router->post('/api/auth/verify-email', 'AuthController::verifyEmail');
$router->post('/api/auth/refresh', 'AuthController::refresh');
$router->post('/api/auth/logout', 'AuthController::logout');
$router->get('/api/auth/me', 'AuthController::me');

// Administrator API: every route here passes through the 'admin' middleware
$router->group(['middleware' => 'admin'], function ($router) {

    $router->get('/api/admin/me', 'AdminController::me');

    $router->get('/api/admin/dashboard/stats', 'DashboardController::stats');

    $router->get('/api/admin/categories', 'CategoriesController::index');
    $router->post('/api/admin/categories', 'CategoriesController::store');
    $router->put('/api/admin/categories/{id}', 'CategoriesController::update');
    $router->delete('/api/admin/categories/{id}', 'CategoriesController::destroy');

    $router->get('/api/admin/rooms', 'RoomsController::index');
    $router->post('/api/admin/rooms', 'RoomsController::store');
    $router->put('/api/admin/rooms/{id}', 'RoomsController::update');
    $router->delete('/api/admin/rooms/{id}', 'RoomsController::destroy');

    $router->get('/api/admin/pricing', 'PricingController::index');
    $router->post('/api/admin/pricing', 'PricingController::store');
    $router->put('/api/admin/pricing/{id}', 'PricingController::update');
    $router->delete('/api/admin/pricing/{id}', 'PricingController::destroy');

    $router->get('/api/admin/customers', 'CustomersController::index');
    $router->put('/api/admin/customers/{id}', 'CustomersController::update');

});

