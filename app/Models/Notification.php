<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Notifier;
use App\Core\Paginator;

final class Notification
{
    public const TYPES = ['new_lead_assigned', 'followup_reminder', 'promise_reminder', 'broadcast'];

    /**
     * Creates an in-app notification and attempts a push. In-app is the source
     * of truth; push is best-effort and never blocks the caller.
     *
     * @param array<string,mixed> $data
     */
    public static function send(
        ?int $userId,
        string $type,
        string $title,
        string $body,
        ?int $loanAccountId = null,
        array $data = [],
        ?int $createdBy = null,
        ?int $branchId = null
    ): int {
        $id = Database::instance()->insert('notifications', [
            'user_id'         => $userId,
            'branch_id'       => $branchId,
            'type'            => $type,
            'title'           => mb_substr($title, 0, 180),
            'body'            => mb_substr($body, 0, 1000),
            'loan_account_id' => $loanAccountId,
            'data'            => $data === [] ? null : json_encode($data, JSON_UNESCAPED_UNICODE),
            'created_by'      => $createdBy,
        ]);

        if ($userId !== null && Notifier::pushConfigured()) {
            $pushed = Notifier::push($userId, $title, $body, array_merge($data, [
                'notification_id' => (string) $id,
                'type'            => $type,
                'loan_account_id' => $loanAccountId === null ? '' : (string) $loanAccountId,
            ]));

            if ($pushed) {
                Database::instance()->update('notifications', ['pushed_at' => date('Y-m-d H:i:s')], ['id' => $id]);
            }
        }

        return $id;
    }

    /**
     * Broadcast to every active user, or to one branch. Rows are inserted per
     * user so read state is per-recipient.
     *
     * @return int number of recipients
     */
    public static function broadcast(string $title, string $body, ?int $branchId, ?int $createdBy, ?string $roleSlug = null): int
    {
        $sql = "SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE u.status = 'active'";
        $params = [];

        if ($branchId !== null) {
            $sql .= ' AND u.branch_id = ?';
            $params[] = $branchId;
        }
        if ($roleSlug !== null && $roleSlug !== '') {
            $sql .= ' AND r.slug = ?';
            $params[] = $roleSlug;
        }

        $recipients = Database::instance()->all($sql, $params);

        foreach ($recipients as $recipient) {
            self::send(
                (int) $recipient['id'],
                'broadcast',
                $title,
                $body,
                null,
                [],
                $createdBy,
                $branchId
            );
        }

        return count($recipients);
    }

    public static function paginateForUser(int $userId, bool $unreadOnly, int $page, int $perPage): Paginator
    {
        $where = '(n.user_id = ? OR n.user_id IS NULL)';
        $params = [$userId];

        if ($unreadOnly) {
            $where .= ' AND n.is_read = 0';
        }

        return Paginator::fromQuery(
            "SELECT COUNT(*) FROM notifications n WHERE {$where}",
            "SELECT n.*, la.loan_account_number
               FROM notifications n
               LEFT JOIN loan_accounts la ON la.id = n.loan_account_id
              WHERE {$where}
              ORDER BY n.created_at DESC, n.id DESC",
            $params,
            $page,
            $perPage
        );
    }

    public static function unreadCount(int $userId): int
    {
        return (int) Database::instance()->scalar(
            'SELECT COUNT(*) FROM notifications WHERE (user_id = ? OR user_id IS NULL) AND is_read = 0',
            [$userId]
        );
    }

    public static function markRead(int $id, int $userId): bool
    {
        $affected = Database::instance()->query(
            'UPDATE notifications SET is_read = 1, read_at = NOW()
              WHERE id = ? AND (user_id = ? OR user_id IS NULL) AND is_read = 0',
            [$id, $userId]
        )->rowCount();

        return $affected > 0;
    }

    public static function markAllRead(int $userId): int
    {
        return Database::instance()->query(
            'UPDATE notifications SET is_read = 1, read_at = NOW()
              WHERE (user_id = ? OR user_id IS NULL) AND is_read = 0',
            [$userId]
        )->rowCount();
    }

    /** @return list<array<string,mixed>> */
    public static function recentForUser(int $userId, int $limit = 8): array
    {
        return Database::instance()->all(
            'SELECT n.id, n.type, n.title, n.body, n.is_read, n.created_at, n.loan_account_id
               FROM notifications n
              WHERE (n.user_id = ? OR n.user_id IS NULL)
              ORDER BY n.created_at DESC
              LIMIT ' . max(1, min(50, $limit)),
            [$userId]
        );
    }
}
