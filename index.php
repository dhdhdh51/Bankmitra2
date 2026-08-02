<?php
/**
 * D2 Recovery Solutions & Services single front controller.
 *
 * Everything - admin panel pages and the /api/v1 REST API - enters here.
 * Deploy the contents of this `admin/` directory into public_html.
 */

declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/Core/helpers.php';

use App\Core\Request;
use App\Core\Router;

$request = new Request();
$router = new Router();

// API routes are registered first so /api/* never falls through to a web route.
(require __DIR__ . '/app/routes/api.php')($router);
(require __DIR__ . '/app/routes/web.php')($router);

$router->dispatch($request);
