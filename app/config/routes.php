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


// Authentication (public)
$router->post('/api/auth/register', 'AuthController::register');
$router->post('/api/auth/login', 'AuthController::login');
$router->post('/api/auth/verify-email', 'AuthController::verifyEmail');
$router->post('/api/auth/refresh', 'AuthController::refresh');
$router->post('/api/auth/logout', 'AuthController::logout');
$router->get('/api/auth/me', 'AuthController::me');
$router->post('/api/auth/forgot-password', 'PasswordResetController::forgot');
$router->post('/api/auth/reset-password', 'PasswordResetController::reset');

// Public catalogue and hotel information
$router->get('/api/public/hotel', 'PublicController::hotel');
$router->get('/api/public/search', 'PublicController::search');
$router->get('/api/public/availability', 'PublicController::availability');
$router->get('/api/public/rooms', 'CatalogueController::index');
$router->get('/api/public/rooms/{id}', 'CatalogueController::show');
$router->get('/api/public/map', 'MapController::show');
$router->get('/api/public/testimonials', 'TestimonialsController::index');
$router->post('/api/payments/xendit/webhook', 'XenditWebhookController::handle');

// Customer: logged-in guests only
$router->group(['middleware' => 'customer'], function ($router) {
    $router->get('/api/customer/profile', 'ProfileController::show');
    $router->put('/api/customer/profile', 'ProfileController::update');
    $router->put('/api/customer/password', 'ProfileController::changePassword');

    $router->get('/api/customer/reservations', 'ReservationsController::index');
    $router->post('/api/customer/reservations', 'ReservationsController::store');
    $router->post('/api/customer/reservations/{id}/cancel', 'ReservationsController::cancel');

    $router->get('/api/customer/change-requests', 'ChangeRequestsController::index');
    $router->post('/api/customer/reservations/{id}/change-requests', 'ChangeRequestsController::store');

    $router->get('/api/customer/inquiries', 'InquiriesController::index');
    $router->post('/api/customer/inquiries', 'InquiriesController::store');

    $router->get('/api/customer/reviews', 'ReviewsController::mine');
    $router->post('/api/customer/reviews', 'ReviewsController::store');

    $router->get('/api/customer/messages', 'CustomerMessagesController::index');
    $router->post('/api/customer/messages', 'CustomerMessagesController::store');
    $router->post('/api/customer/messages/{id}/reply', 'CustomerMessagesController::reply');

    $router->get('/api/customer/services', 'CustomerServicesController::catalog');
    $router->get('/api/customer/reservations/{id}/services', 'CustomerServicesController::forReservation');
    $router->post('/api/customer/reservations/{id}/services', 'CustomerServicesController::add');
    $router->post('/api/customer/reservation-services/{id}/cancel', 'CustomerServicesController::cancel');

    $router->get('/api/customer/messages/unread-count', 'CustomerMessagesController::unreadCount');
    $router->put('/api/customer/messages/read', 'CustomerMessagesController::markRead');

    $router->get('/api/customer/reservations/{id}/payments', 'CustomerPaymentsController::index');
    $router->post('/api/customer/reservations/{id}/downpayment', 'CustomerPaymentsController::downpayment');
});

// Staff (front desk): admin, manager, and staff
$router->group(['middleware' => 'staff'], function ($router) {
    $router->get('/api/admin/me', 'AuthController::me');

    $router->get('/api/admin/customers', 'CustomersController::index');

    $router->get('/api/admin/availability', 'AvailabilityController::index');
    $router->get('/api/admin/room-blocks', 'RoomBlocksController::index');
    $router->post('/api/admin/room-blocks', 'RoomBlocksController::store');
    $router->delete('/api/admin/room-blocks/{id}', 'RoomBlocksController::destroy');

    $router->get('/api/admin/reservations', 'AdminReservationsController::index');
    $router->post('/api/admin/reservations/{id}/confirm', 'AdminReservationsController::confirm');
    $router->post('/api/admin/reservations/{id}/reject', 'AdminReservationsController::reject');

    $router->get('/api/admin/check-in-out', 'CheckinController::index');
    $router->post('/api/admin/reservations/{id}/check-in', 'CheckinController::checkIn');
    $router->post('/api/admin/reservations/{id}/check-out', 'CheckinController::checkOut');

    $router->get('/api/admin/messages', 'AdminMessagesController::index');
    $router->get('/api/admin/messages/{id}', 'AdminMessagesController::thread');
    $router->post('/api/admin/messages/{id}/reply', 'AdminMessagesController::reply');
    $router->put('/api/admin/messages/{id}/read', 'AdminMessagesController::setRead');

    $router->get('/api/admin/service-requests', 'AdminServiceRequestsController::index');
    $router->post('/api/admin/service-requests/{id}/confirm', 'AdminServiceRequestsController::confirm');
    $router->post('/api/admin/service-requests/{id}/complete', 'AdminServiceRequestsController::complete');
});

// Manager: admin and manager
$router->group(['middleware' => 'manager'], function ($router) {
    $router->get('/api/admin/dashboard/stats', 'DashboardController::stats');

    $router->get('/api/admin/categories', 'CategoriesController::index');
    $router->post('/api/admin/categories', 'CategoriesController::store');
    $router->put('/api/admin/categories/{id}', 'CategoriesController::update');
    $router->delete('/api/admin/categories/{id}', 'CategoriesController::destroy');

    $router->get('/api/admin/rooms', 'RoomsController::index');
    $router->post('/api/admin/rooms/upload', 'RoomsController::upload');
    $router->post('/api/admin/rooms', 'RoomsController::store');
    $router->put('/api/admin/rooms/{id}', 'RoomsController::update');
    $router->delete('/api/admin/rooms/{id}', 'RoomsController::destroy');

    $router->get('/api/admin/pricing', 'PricingController::index');
    $router->post('/api/admin/pricing', 'PricingController::store');
    $router->put('/api/admin/pricing/{id}', 'PricingController::update');
    $router->delete('/api/admin/pricing/{id}', 'PricingController::destroy');

    $router->put('/api/admin/customers/{id}', 'CustomersController::update');

    $router->get('/api/admin/reviews', 'AdminReviewsController::index');
    $router->put('/api/admin/reviews/{id}', 'AdminReviewsController::update');

    $router->get('/api/admin/service-catalog', 'AdminServiceCatalogController::index');
    $router->post('/api/admin/service-catalog', 'AdminServiceCatalogController::store');
    $router->put('/api/admin/service-catalog/{id}', 'AdminServiceCatalogController::update');
    $router->post('/api/admin/service-requests/{id}/billed', 'AdminServiceRequestsController::markBilled');
});

// Admin only
$router->group(['middleware' => 'admin'], function ($router) {
    $router->get('/api/admin/staff', 'StaffAccountsController::index');
    $router->post('/api/admin/staff', 'StaffAccountsController::store');
    $router->put('/api/admin/staff/{id}', 'StaffAccountsController::update');
    $router->put('/api/admin/staff/{id}/password', 'StaffAccountsController::resetPassword');

    $router->get('/api/admin/hotel', 'AdminHotelController::show');
    $router->put('/api/admin/hotel', 'AdminHotelController::update');
    $router->put('/api/admin/map', 'MapController::update');
});
