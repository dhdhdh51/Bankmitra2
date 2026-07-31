<?php
/**
 * REST API v1 routes.
 *
 * JWT (Bearer) authentication. Every response uses the envelope:
 *   { "success": true, "data": {...}, "message": "" }
 *
 * List endpoints accept: page, per_page, search, sort_by, sort_dir plus
 * resource-specific filters, and return a `meta` block with pagination info.
 *
 * Authorisation lives in the controllers' shared base class (Api\Controller),
 * so no endpoint can ship without an auth + permission check.
 */

declare(strict_types=1);

use App\Controllers\Api\AuthController;
use App\Controllers\Api\ImportController;
use App\Controllers\Api\LeadController;
use App\Controllers\Api\MediaController;
use App\Controllers\Api\MetaController;
use App\Controllers\Api\NotificationController;
use App\Controllers\Api\ReportController;
use App\Controllers\Api\VisitController;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;

return static function (Router $router): void {

    $prefix = '/api/v1';

    // ---- Public ----------------------------------------------------------
    $router->get($prefix . '/ping', [MetaController::class, 'ping']);

    // ---- Auth ------------------------------------------------------------
    $router->post($prefix . '/auth/login', [AuthController::class, 'login']);
    $router->post($prefix . '/auth/refresh', [AuthController::class, 'refresh']);
    $router->post($prefix . '/auth/logout', [AuthController::class, 'logout']);
    $router->post($prefix . '/auth/forgot-password', [AuthController::class, 'forgotPassword']);
    $router->post($prefix . '/auth/reset-password', [AuthController::class, 'resetPassword']);
    $router->post($prefix . '/auth/change-password', [AuthController::class, 'changePassword']);
    $router->get($prefix . '/auth/me', [AuthController::class, 'me']);
    $router->post($prefix . '/auth/device-token', [AuthController::class, 'deviceToken']);

    // ---- Metadata & dashboard -------------------------------------------
    $router->get($prefix . '/meta', [MetaController::class, 'meta']);
    $router->get($prefix . '/dashboard', [MetaController::class, 'dashboard']);

    // ---- Leads -----------------------------------------------------------
    $router->get($prefix . '/leads', [LeadController::class, 'index']);
    $router->get($prefix . '/leads/search', [LeadController::class, 'search']);
    $router->post($prefix . '/leads/assign', [LeadController::class, 'assign']);
    $router->post($prefix . '/leads/reassign', [LeadController::class, 'assign']);
    $router->post($prefix . '/leads/transfer', [LeadController::class, 'transfer']);
    $router->post($prefix . '/leads/status', [LeadController::class, 'updateStatus']);

    // ---- Customers -------------------------------------------------------
    $router->get($prefix . '/customers/{id}', [LeadController::class, 'show']);
    $router->get($prefix . '/customers/{id}/history', [LeadController::class, 'history']);

    // ---- Visits ----------------------------------------------------------
    $router->get($prefix . '/visits', [VisitController::class, 'index']);
    $router->post($prefix . '/visits', [VisitController::class, 'store']);
    $router->get($prefix . '/visits/form-options', [VisitController::class, 'formOptions']);
    $router->get($prefix . '/visits/{id}', [VisitController::class, 'show']);

    // ---- Promises --------------------------------------------------------
    $router->get($prefix . '/promises', [MetaController::class, 'promises']);
    $router->post($prefix . '/promises/{id}/settle', [MetaController::class, 'settlePromise']);

    // ---- Notifications ---------------------------------------------------
    $router->get($prefix . '/notifications', [NotificationController::class, 'index']);
    $router->get($prefix . '/notifications/unread-count', [NotificationController::class, 'unreadCount']);
    $router->post($prefix . '/notifications/read-all', [NotificationController::class, 'readAll']);
    $router->post($prefix . '/notifications/send', [NotificationController::class, 'send']);
    $router->post($prefix . '/notifications/{id}/read', [NotificationController::class, 'read']);

    // ---- Import ----------------------------------------------------------
    $router->get($prefix . '/import', [ImportController::class, 'index']);
    $router->post($prefix . '/import/upload', [ImportController::class, 'upload']);
    $router->post($prefix . '/import/preview', [ImportController::class, 'preview']);
    $router->get($prefix . '/import/{id}/errors', [ImportController::class, 'errors']);

    // ---- Reports ---------------------------------------------------------
    $router->get($prefix . '/reports', [ReportController::class, 'index']);
    $router->get($prefix . '/reports/{type}', [ReportController::class, 'show']);
    $router->get($prefix . '/reports/{type}/export', [ReportController::class, 'export']);

    // ---- Protected media -------------------------------------------------
    // Returns the image/PDF bytes for a stored upload. The app fetches these
    // with its Bearer token; the files are not web-readable on disk.
    $router->get($prefix . '/media', [MediaController::class, 'show']);

    // Native apps do not send CORS preflights, so none is configured here.
    // Add one deliberately if a browser-based client is ever introduced.
};
