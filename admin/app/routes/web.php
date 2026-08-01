<?php
/**
 * Admin panel routes.
 *
 * Guards (authentication, RBAC, CSRF, branch scoping) live in the controllers'
 * shared base class so every action is protected by construction rather than by
 * remembering to add middleware here.
 */

declare(strict_types=1);

use App\Controllers\Admin\AuthController;
use App\Controllers\Admin\BackupController;
use App\Controllers\Admin\BcTargetController;
use App\Controllers\Admin\BranchController;
use App\Controllers\Admin\CustomerController;
use App\Controllers\Admin\DashboardController;
use App\Controllers\Admin\ImportController;
use App\Controllers\Admin\LogController;
use App\Controllers\Admin\MediaController;
use App\Controllers\Admin\NotificationController;
use App\Controllers\Admin\PromiseController;
use App\Controllers\Admin\ReportController;
use App\Controllers\Admin\RoleController;
use App\Controllers\Admin\ScorecardController;
use App\Controllers\Admin\SettingsController;
use App\Controllers\Admin\SssController;
use App\Controllers\Admin\UserController;
use App\Controllers\Admin\VisitController;
use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\View;

return static function (Router $router): void {

    // ---- Public / auth ---------------------------------------------------
    $router->get('/', [AuthController::class, 'root']);
    $router->form('/login', [AuthController::class, 'login']);
    $router->post('/logout', [AuthController::class, 'logout']);
    $router->get('/logout', [AuthController::class, 'logout']);
    $router->form('/forgot-password', [AuthController::class, 'forgotPassword']);
    $router->form('/reset-password', [AuthController::class, 'resetPassword']);
    $router->form('/change-password', [AuthController::class, 'changePassword']);

    // ---- Dashboard -------------------------------------------------------
    $router->get('/dashboard', [DashboardController::class, 'index']);

    // ---- Customers / leads ----------------------------------------------
    $router->get('/customers', [CustomerController::class, 'index']);
    $router->post('/customers/bulk', [CustomerController::class, 'bulk']);
    $router->get('/customers/export', [CustomerController::class, 'export']);
    $router->get('/customers/{id}', [CustomerController::class, 'show']);
    $router->form('/customers/{id}/edit', [CustomerController::class, 'edit']);

    // ---- Visit reports ---------------------------------------------------
    $router->get('/visits', [VisitController::class, 'index']);
    $router->get('/visits/{id}', [VisitController::class, 'show']);
    $router->get('/visits/{id}/pdf', [VisitController::class, 'pdf']);

    // ---- Promises --------------------------------------------------------
    $router->get('/promises', [PromiseController::class, 'index']);
    $router->post('/promises/{id}/settle', [PromiseController::class, 'settle']);

    // ---- Excel import ----------------------------------------------------
    $router->form('/import', [ImportController::class, 'index']);
    $router->post('/import/preview', [ImportController::class, 'preview']);
    $router->get('/import/history', [ImportController::class, 'history']);
    $router->get('/import/template', [ImportController::class, 'template']);
    $router->get('/import/{id}/errors', [ImportController::class, 'errors']);

    // ---- Branches --------------------------------------------------------
    $router->get('/branches', [BranchController::class, 'index']);
    $router->form('/branches/create', [BranchController::class, 'create']);
    $router->form('/branches/{id}/edit', [BranchController::class, 'edit']);
    $router->post('/branches/{id}/delete', [BranchController::class, 'delete']);

    // ---- Users -----------------------------------------------------------
    $router->get('/users', [UserController::class, 'index']);
    $router->form('/users/create', [UserController::class, 'create']);
    $router->form('/users/{id}/edit', [UserController::class, 'edit']);
    $router->post('/users/{id}/reset-password', [UserController::class, 'resetPassword']);
    $router->post('/users/{id}/status', [UserController::class, 'status']);
    $router->post('/users/{id}/delete', [UserController::class, 'delete']);

    // ---- Roles & permissions --------------------------------------------
    $router->get('/roles', [RoleController::class, 'index']);
    $router->post('/roles/{id}/permissions', [RoleController::class, 'update']);

    // ---- Reports ---------------------------------------------------------
    $router->get('/reports', [ReportController::class, 'index']);
    $router->get('/reports/{type}', [ReportController::class, 'show']);
    $router->get('/reports/{type}/export', [ReportController::class, 'export']);

    // ---- BC performance --------------------------------------------------
    // Targets are what the nightly warning check measures against, SSS is the
    // enrolment count the scorecard sums, and the scorecard is the ranking those
    // two produce. Export is on the same permission as viewing: exporting a table
    // somebody can already read is not a separate capability.
    $router->get ('/bc/targets', [BcTargetController::class, 'index']);
    $router->form('/bc/targets/create', [BcTargetController::class, 'create']);
    $router->form('/bc/targets/{id}/edit', [BcTargetController::class, 'edit']);
    $router->post('/bc/targets/{id}/delete', [BcTargetController::class, 'delete']);

    $router->get ('/bc/sss', [SssController::class, 'index']);
    $router->form('/bc/sss/create', [SssController::class, 'create']);
    $router->form('/bc/sss/{id}/edit', [SssController::class, 'edit']);
    $router->post('/bc/sss/{id}/delete', [SssController::class, 'delete']);

    // Registered before the bare path so /export is never read as a route param.
    $router->get('/bc/scorecard/export', [ScorecardController::class, 'export']);
    $router->get('/bc/scorecard', [ScorecardController::class, 'index']);

    // ---- Notifications ---------------------------------------------------
    $router->get('/notifications', [NotificationController::class, 'index']);
    $router->post('/notifications/read-all', [NotificationController::class, 'readAll']);
    $router->post('/notifications/{id}/read', [NotificationController::class, 'read']);
    $router->form('/notifications/send', [NotificationController::class, 'send']);

    // ---- Logs ------------------------------------------------------------
    $router->get('/logs/audit', [LogController::class, 'audit']);
    $router->get('/logs/activity', [LogController::class, 'activity']);

    // ---- Backup ----------------------------------------------------------
    $router->get('/backup', [BackupController::class, 'index']);
    $router->post('/backup/run', [BackupController::class, 'run']);
    $router->get('/backup/download', [BackupController::class, 'download']);
    $router->post('/backup/delete', [BackupController::class, 'delete']);

    // ---- Settings --------------------------------------------------------
    $router->form('/settings', [SettingsController::class, 'index']);

    // ---- Protected media (photos, documents, signatures) ----------------
    $router->get('/media', [MediaController::class, 'show']);

    // ---- 404 -------------------------------------------------------------
    $router->notFound(static function (Request $request): void {
        if ($request->wantsJson()) {
            Response::notFound('Endpoint not found');
        }

        Auth::resolve($request);
        http_response_code(404);

        if (Auth::check() && !Auth::isAgent()) {
            View::render('errors/404', [
                'title'       => 'Page not found',
                'currentPath' => $request->path(),
            ]);
        }

        View::render('errors/404', [
            'title'       => 'Page not found',
            'currentPath' => $request->path(),
        ], 'layouts/bare');
    });
};
