<?php
define('PREVENT_DIRECT_ACCESS', TRUE);
define('APP_REQUEST_STARTED_AT', microtime(true));

register_shutdown_function(static function () {
    $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    if (strpos($requestPath, '/api/') !== 0) {
        return;
    }

    $route = preg_replace('~/[0-9]+(?=/|$)~', '/{id}', $requestPath);
    $route = preg_replace('~/[a-f0-9-]{24,}(?=/|$)~i', '/{id}', $route);
    $status = http_response_code();
    $metrics = [
        'connection_count' => 0,
        'connection_ms'    => 0,
        'query_count'      => 0,
        'query_ms'         => 0,
        'query_max_ms'     => 0,
        'slow_queries'     => [],
    ];

    if (function_exists('lava_instance')) {
        $controller = lava_instance();
        $db = is_object($controller) ? ($controller->properties['db'] ?? null) : null;
        if (is_object($db) && method_exists($db, 'get_performance_metrics')) {
            $metrics = $db->get_performance_metrics();
        }
    }

    error_log('api_performance ' . json_encode([
        'method'        => strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
        'route'         => $route,
        'status'        => is_int($status) ? $status : 200,
        'duration_ms'   => round((microtime(true) - APP_REQUEST_STARTED_AT) * 1000, 2),
        'connection_count' => $metrics['connection_count'],
        'connection_ms' => $metrics['connection_ms'],
        'query_count'   => $metrics['query_count'],
        'query_ms'      => $metrics['query_ms'],
        'query_max_ms'  => $metrics['query_max_ms'],
        'slow_queries'  => $metrics['slow_queries'],
    ], JSON_UNESCAPED_SLASHES));
});

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
 * @copyright Copyright 2020 (https://ronmarasigan.github.io)
 * @since Version 1
 * @link https://lavalust.pinoywap.org
 * @license https://opensource.org/licenses/MIT MIT License
 */

/*
 *---------------------------------------------------------------
 * SYSTEM DIRECTORY NAME
 *---------------------------------------------------------------
 *
 * This variable must contain the name of your "scheme" directory.
 * Set the path if it is not in the same directory as this file.
 * 
 * NO TRAILING SLASH!
 */
	$system_path 			= 'scheme';

/*
 *---------------------------------------------------------------
 * APPLICATION DIRECTORY NAME
 *---------------------------------------------------------------
 *
 * If you want this front controller to use a different "app"
 * directory than the default one you can set its name here.
 *
 * NO TRAILING SLASH!
 */
	$application_folder 	= 'app';

/*
 *---------------------------------------------------------------
 * APPLICATION DIRECTORY NAME
 *---------------------------------------------------------------
 * This let you set up your public folder where css, js and other public,
 * files will be visible
 */
	$public_folder			= 'public';

/*
 * ------------------------------------------------------
 * Define Application Constants
 * ------------------------------------------------------
 */
define('ROOT_DIR',  dirname(__DIR__) . DIRECTORY_SEPARATOR);
define('SYSTEM_DIR', ROOT_DIR . $system_path . DIRECTORY_SEPARATOR);
define('APP_DIR', ROOT_DIR . $application_folder . DIRECTORY_SEPARATOR);
define('PUBLIC_DIR', $public_folder);

/*
 * ------------------------------------------------------
 * Load Composer dependencies
 * ------------------------------------------------------
 */
require_once ROOT_DIR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

/*
 * ------------------------------------------------------
 * Setup done? Then Hurray!
 * ------------------------------------------------------
 */
require_once SYSTEM_DIR . 'kernel/LavaLust.php';

/*
 * ------------------------------------------------------
 * Load environment variables from .env (if present)
 * ------------------------------------------------------
 */
$dotenv = Dotenv\Dotenv::createImmutable(ROOT_DIR);
$dotenv->safeLoad();
?>
