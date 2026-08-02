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
     * @param bool        $allowAgent Whether a BC/DC agent may reach this action.
     *                                Defaults to false: a new screen is closed to
     *                                agents until somebody says otherwise, because
     *                                forgetting the flag hides a page while forgetting
     *                                a check would expose one.
     */
    protected function guard(Request $request, ?string $permission = null, bool $allowAgent = false): void
    {
        Auth::requirePanel($request, $allowAgent);

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
     * An agent may only open a record assigned to them.
     *
     * Branch scope is not enough. A branch holds several agents, and one of them
     * reading a colleague's borrower - name, address, family, phone number - is not
     * something the job needs. Callers run this after assertBranchAccess(), which has
     * already dealt with everybody who is not an agent.
     *
     * @param array<string,mixed> $lead
     */
    protected function assertOwnLead(array $lead): void
    {
        $agentId = Auth::scopedAgentId();
        if ($agentId === null) {
            return;
        }

        $assigned = $lead['assigned_agent_id'] ?? null;
        if ($assigned === null || (int) $assigned !== $agentId) {
            $this->back('/customers', 'danger', 'That borrower is not assigned to you.');
        }
    }

    /**
     * Agent filter, constrained to the caller's branch.
     */
    protected function agentFilter(Request $request): ?int
    {
        // An agent's list is their own leads, and not negotiable by query string. This
        // sits before the request is read at all so ?agent_id=<colleague> cannot widen
        // it - the parameter is simply never consulted for an agent.
        $own = Auth::scopedAgentId();
        if ($own !== null) {
            return $own;
        }

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

    /**
     * Handles one optional image field on a panel form.
     *
     * Returns the relative path to store, which may be the one that was already
     * there. Three outcomes, and the distinction between them is the whole reason
     * this is not two lines inline:
     *
     *   nothing submitted  -> $existing is returned unchanged. A form saved without
     *                         touching the file input must not wipe the image, which
     *                         is what happens if absence is read as "clear it".
     *   remove requested   -> null, and the old file is deleted from disk.
     *   file submitted     -> stored, and the old file is deleted afterwards.
     *
     * The old file is removed only once the new one is safely on disk, so a failed
     * upload leaves the record with the image it had rather than with none.
     *
     * @throws \RuntimeException with a message fit to show the user
     */
    protected function optionalImage(
        string $field,
        string $kind,
        ?string $existing = null,
        bool $remove = false
    ): ?string {
        if ($remove) {
            if ($existing !== null && $existing !== '') {
                \App\Core\Uploader::delete($existing);
            }

            return null;
        }

        if (!\App\Core\Uploader::hasUpload($field)) {
            return $existing;
        }

        $files = \App\Core\Uploader::normalizeMultiple($field);
        if ($files === []) {
            return $existing;
        }

        $allowed = (array) \App\Core\Config::get(
            'uploads.allowed_image_mime',
            ['image/jpeg', 'image/png', 'image/webp']
        );
        $max = (int) \App\Core\Config::get('uploads.max_photo_bytes', 8 * 1024 * 1024);

        // Uploader sniffs the real MIME rather than trusting the browser, and throws
        // on anything that is not a decodable image.
        $stored = \App\Core\Uploader::store($files[0], $kind, $allowed, $max);

        if ($existing !== null && $existing !== '' && $existing !== $stored['path']) {
            \App\Core\Uploader::delete($existing);
        }

        return $stored['path'];
    }
}
