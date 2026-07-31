<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Models\Notification;

final class NotificationController extends Controller
{
    public function index(Request $request): void
    {
        $user = $this->auth($request, 'notifications.view');

        $page = Notification::paginateForUser(
            (int) $user['id'],
            $request->bool('unread'),
            $request->page(),
            $this->perPage($request)
        );

        Response::success(
            array_map(static fn (array $row): array => [
                'id'                  => (int) $row['id'],
                'type'                => (string) $row['type'],
                'title'               => (string) $row['title'],
                'body'                => $row['body'] === null ? null : (string) $row['body'],
                'is_read'             => (int) $row['is_read'] === 1,
                'loan_account_id'     => $row['loan_account_id'] === null ? null : (int) $row['loan_account_id'],
                'loan_account_number' => $row['loan_account_number'] === null ? null : (string) $row['loan_account_number'],
                'created_at'          => (string) $row['created_at'],
            ], $page->items),
            '',
            [
                'meta'         => $page->meta(),
                'unread_count' => Notification::unreadCount((int) $user['id']),
            ]
        );
    }

    public function read(Request $request): void
    {
        $user = $this->auth($request, 'notifications.view');

        $marked = Notification::markRead($request->paramInt('id'), (int) $user['id']);

        Response::success(
            ['unread_count' => Notification::unreadCount((int) $user['id'])],
            $marked ? 'Marked as read.' : 'Already read.'
        );
    }

    public function readAll(Request $request): void
    {
        $user = $this->auth($request, 'notifications.view');

        $count = Notification::markAllRead((int) $user['id']);

        Response::success(
            ['marked' => $count, 'unread_count' => 0],
            sprintf('%d notification(s) marked as read.', $count)
        );
    }

    public function unreadCount(Request $request): void
    {
        $user = $this->auth($request, 'notifications.view');

        Response::success(['unread_count' => Notification::unreadCount((int) $user['id'])]);
    }

    /** Broadcast, for managers and admins. */
    public function send(Request $request): void
    {
        $user = $this->auth($request, 'notifications.send');

        $this->validate($request, [
            'title' => 'required|max:180',
            'body'  => 'required|max:1000',
        ], ['body' => 'Message']);

        $scoped = Auth::scopedBranchId();
        $branchId = $scoped ?? ($request->nullableInt('branch_id') ?: null);

        $roleSlug = $request->str('role_slug');
        if (!in_array($roleSlug, ['', 'agent', 'branch_manager'], true)) {
            $roleSlug = '';
        }

        $recipients = Notification::broadcast(
            $request->str('title'),
            $request->str('body'),
            $branchId,
            (int) $user['id'],
            $roleSlug === '' ? null : $roleSlug
        );

        Response::success(['recipients' => $recipients], sprintf('Sent to %d recipient(s).', $recipients));
    }
}
