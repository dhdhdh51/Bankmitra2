<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * The append-only lead timeline (`visit_history`).
 *
 * Lead Imported -> Assigned -> Visit 1 -> Visit 2 -> Promise Created ->
 * Promise Broken -> Visit 3 -> Closed
 *
 * Only record() writes here. Nothing updates or deletes an event.
 */
final class Timeline
{
    /** @var array<string,array{label:string,icon:string,tone:string}> */
    public const EVENTS = [
        'lead_imported'   => ['label' => 'Lead imported',    'icon' => 'upload',   'tone' => 'slate'],
        // Typed into the panel rather than read out of a bank export. A different icon
        // and label on purpose: somebody reading the trail years later needs to see at a
        // glance which figures came from the core banking system and which from a person.
        'lead_created'    => ['label' => 'Lead created by hand', 'icon' => 'edit',   'tone' => 'warning'],
        'lead_updated'    => ['label' => 'Lead updated',     'icon' => 'refresh',  'tone' => 'slate'],
        'assigned'        => ['label' => 'Assigned',         'icon' => 'user',     'tone' => 'blue'],
        'reassigned'      => ['label' => 'Reassigned',       'icon' => 'swap',     'tone' => 'blue'],
        'transferred'     => ['label' => 'Transferred',      'icon' => 'branch',   'tone' => 'blue'],
        'visit'           => ['label' => 'Field visit',      'icon' => 'clipboard', 'tone' => 'success'],
        'promise_created' => ['label' => 'Promise created',  'icon' => 'handshake', 'tone' => 'warning'],
        'promise_kept'    => ['label' => 'Promise kept',     'icon' => 'check',    'tone' => 'success'],
        'promise_broken'  => ['label' => 'Promise broken',   'icon' => 'alert',    'tone' => 'danger'],
        'status_changed'  => ['label' => 'Status changed',   'icon' => 'refresh',  'tone' => 'slate'],
        'closed'          => ['label' => 'Closed',           'icon' => 'lock',     'tone' => 'slate'],
        'reopened'        => ['label' => 'Reopened',         'icon' => 'unlock',   'tone' => 'warning'],
        'note'            => ['label' => 'Note',             'icon' => 'note',     'tone' => 'slate'],
        'visit_approved'  => ['label' => 'Visit approved',   'icon' => 'check-circle', 'tone' => 'success'],
        'visit_rejected'  => ['label' => 'Visit rejected',   'icon' => 'x',        'tone' => 'danger'],
        'visit_revised'   => ['label' => 'Visit corrected',  'icon' => 'pen',      'tone' => 'warning'],
    ];

    /**
     * Appends one timeline event.
     *
     * @param array<string,mixed>|null $meta
     */
    public static function record(
        int $loanAccountId,
        string $eventType,
        string $title,
        ?string $description = null,
        ?int $actorId = null,
        ?string $actorName = null,
        ?int $visitReportId = null,
        ?int $promiseId = null,
        ?array $meta = null
    ): int {
        return Database::instance()->insert('visit_history', [
            'loan_account_id' => $loanAccountId,
            'event_type'      => $eventType,
            'event_at'        => date('Y-m-d H:i:s'),
            'actor_id'        => $actorId,
            'actor_name'      => $actorName,
            'visit_report_id' => $visitReportId,
            'promise_id'      => $promiseId,
            'title'           => mb_substr($title, 0, 180),
            'description'     => $description === null ? null : mb_substr($description, 0, 1000),
            'meta'            => $meta === null || $meta === []
                ? null
                : json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    /**
     * Full timeline for one lead, newest first, with the attached visit's media
     * counts so the UI can render thumbnails without an N+1 query.
     *
     * @return list<array<string,mixed>>
     */
    public static function forLoanAccount(int $loanAccountId, int $limit = 200): array
    {
        $rows = Database::instance()->all(
            'SELECT vh.*,
                    vr.visit_date, vr.visit_time, vr.remarks AS visit_remarks,
                    vr.customer_met, vr.house_locked, vr.promise_amount AS visit_promise_amount,
                    vr.promise_date AS visit_promise_date,
                    p.promise_amount, p.promise_date, p.status AS promise_status,
                    (SELECT COUNT(*) FROM photos ph WHERE ph.visit_report_id = vh.visit_report_id) AS photo_count
               FROM visit_history vh
               LEFT JOIN visit_reports vr ON vr.id = vh.visit_report_id
               LEFT JOIN promises p ON p.id = vh.promise_id
              WHERE vh.loan_account_id = ?
              ORDER BY vh.event_at DESC, vh.id DESC
              LIMIT ' . max(1, min(500, $limit)),
            [$loanAccountId]
        );

        foreach ($rows as $index => $row) {
            $meta = $row['meta'] ?? null;
            $rows[$index]['meta_decoded'] = is_string($meta) ? (json_decode($meta, true) ?: []) : [];
            $rows[$index]['event_meta'] = self::EVENTS[(string) $row['event_type']]
                ?? ['label' => ucfirst(str_replace('_', ' ', (string) $row['event_type'])), 'icon' => 'note', 'tone' => 'slate'];
        }

        return $rows;
    }

    /**
     * Photos attached to a specific timeline entry's visit.
     *
     * @return list<array<string,mixed>>
     */
    public static function photosFor(?int $visitReportId): array
    {
        if ($visitReportId === null) {
            return [];
        }
        return Database::instance()->all(
            'SELECT id, photo_type, file_path FROM photos WHERE visit_report_id = ? ORDER BY id ASC',
            [$visitReportId]
        );
    }

    public static function countForLoanAccount(int $loanAccountId): int
    {
        return (int) Database::instance()->scalar(
            'SELECT COUNT(*) FROM visit_history WHERE loan_account_id = ?',
            [$loanAccountId]
        );
    }
}
