<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Validator;
use App\Models\Branch;
use App\Models\Notification;

final class NotificationController extends Controller
{
    public function index(Request $request): void
    {
        $this->guard($request, 'notifications.view');

        $userId = (int) Auth::id();
        $unreadOnly = $request->bool('unread');

        $notifications = Notification::paginateForUser(
            $userId,
            $unreadOnly,
            $request->page(),
            $this->perPage($request)
        );

        $this->view($request, 'notifications/index', [
            'title'         => 'Notifications',
            'notifications' => $notifications,
            'unreadOnly'    => $unreadOnly,
            'unreadTotal'   => Notification::unreadCount($userId),
            'canSend'       => Auth::can('notifications.send'),
        ]);
    }

    public function read(Request $request): void
    {
        $this->guard($request, 'notifications.view');

        Notification::markRead($request->paramInt('id'), (int) Auth::id());

        // Follow the notification through to the record it refers to when asked.
        $returnTo = $request->str('return_to');
        $this->back(str_starts_with($returnTo, '/') ? $returnTo : '/notifications', 'success', 'Marked as read.');
    }

    public function readAll(Request $request): void
    {
        $this->guard($request, 'notifications.view');

        $count = Notification::markAllRead((int) Auth::id());

        $this->back('/notifications', 'success', $count > 0
            ? sprintf('%d notification(s) marked as read.', $count)
            : 'Nothing was unread.');
    }

    /** Broadcast to all users, one branch, or one role. */
    public function send(Request $request): void
    {
        $this->guard($request, 'notifications.send');

        $scoped = Auth::scopedBranchId();

        if (!$request->isPost()) {
            $this->view($request, 'notifications/send', [
                'title'    => 'Send notification',
                'branches' => Branch::options($scoped),
                'roles'    => [
                    ''               => 'Everyone',
                    'agent'          => 'BC Agents only',
                    'branch_manager' => 'Branch Managers only',
                ],
            ]);
        }

        $validator = Validator::make($request->all(), [
            'title' => 'required|max:180',
            'body'  => 'required|max:1000',
        ], ['body' => 'Message']);

        if ($validator->fails()) {
            $this->backWithErrors('/notifications/send', $validator->errors(), $request->all());
        }

        // A branch manager can only ever broadcast inside their own branch.
        $branchId = $scoped ?? ($request->nullableInt('branch_id') ?: null);

        $roleSlug = $request->str('role_slug');
        if (!in_array($roleSlug, ['', 'agent', 'branch_manager'], true)) {
            $roleSlug = '';
        }

        $recipients = Notification::broadcast(
            $request->str('title'),
            $request->str('body'),
            $branchId,
            Auth::id(),
            $roleSlug === '' ? null : $roleSlug
        );

        \App\Core\Logger::audit(
            'create',
            'notification',
            null,
            null,
            ['title' => $request->str('title'), 'recipients' => $recipients, 'branch_id' => $branchId],
            sprintf('Broadcast sent to %d recipient(s)', $recipients)
        );

        $this->back('/notifications', $recipients > 0 ? 'success' : 'warning', $recipients > 0
            ? sprintf('Notification sent to <strong>%d</strong> recipient(s).', $recipients)
            : 'No active recipients matched that selection.');
    }
}
