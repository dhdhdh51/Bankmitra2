<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Logger;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Settings;
use App\Core\View;
use App\Models\Notification;

/**
 * Base class for every admin panel controller.
 *
 * Subclasses call guard() as the first statement of each action. That single
 * call performs authentication, the first-login password change redirect, the
 * agents-are-app-only block, CSRF verification on writes, and the per-action
 * permission check - so no action can accidentally ship unprotected.
 */
abstract class Controller
{
    /**
     * @param string|null $permission Required permission, or null for any signed-in user.
     */
    protected function guard(Request $request, ?string $permission = null): void
    {
        Auth::requirePanel($request);

        // Cookie-authenticated writes need a CSRF token.
        if (in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            Csrf::enforce($request);
        }

        if ($permission !== null) {
            Auth::requirePermissionPanel($permission);
        }
    }

    /**
     * Renders a page inside the authenticated layout, adding the data every
     * page needs (current path for nav highlighting, unread notification count).
     *
     * @param array<string,mixed> $data
     */
    protected function view(Request $request, string $template, array $data = []): never
    {
        $userId = Auth::id();

        View::render($template, array_merge([
            'currentPath'          => $request->path(),
            'unreadNotifications'  => $userId === null ? 0 : Notification::unreadCount($userId),
        ], $data));
    }

    /**
     * The branch a manager is locked to, or the requested branch filter for a
     * super admin. Every list screen funnels its branch filter through this so
     * branch isolation cannot be bypassed with a query parameter.
     */
    protected function branchFilter(Request $request): ?int
    {
        $scoped = Auth::scopedBranchId();
        if ($scoped !== null) {
            return $scoped;
        }

        $requested = $request->nullableInt('branch_id');
        return ($requested !== null && $requested > 0) ? $requested : null;
    }

    /**
     * Agent filter, constrained to the caller's branch.
     */
    protected function agentFilter(Request $request): ?int
    {
        $agentId = $request->nullableInt('agent_id');
        if ($agentId === null || $agentId <= 0) {
            return null;
        }

        // A branch manager must not be able to inspect another branch's agent.
        $scoped = Auth::scopedBranchId();
        if ($scoped !== null) {
            $agent = \App\Models\User::find($agentId);
            if ($agent === null || (int) ($agent['branch_id'] ?? 0) !== $scoped) {
                return null;
            }
        }

        return $agentId;
    }

    /** Flash + redirect helper. */
    protected function back(string $path, string $type, string $message): never
    {
        Session::flash($type, $message);
        Response::redirect($path);
    }

    /**
     * Re-displays a form with validation errors and the previous input.
     *
     * @param array<string,list<string>> $errors
     * @param array<string,mixed>        $input
     */
    protected function backWithErrors(string $path, array $errors, array $input, string $message = 'Please correct the highlighted fields.'): never
    {
        Session::flashInput($input, $errors);
        Session::flash('danger', $message);
        Response::redirect($path);
    }

    /** Records a page view in the activity log. */
    protected function logView(string $module, string $description): void
    {
        Logger::activity('view', $module, $description);
    }

    protected function logExport(string $module, string $description): void
    {
        Logger::activity('export', $module, $description);
    }

    protected function perPage(Request $request): int
    {
        return $request->perPage((int) Settings::get('records_per_page', '25'));
    }
}
