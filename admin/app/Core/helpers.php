<?php

declare(strict_types=1);

/**
 * Global template helpers.
 *
 * e() is the single escaping entry point used by every view. Anything echoed in
 * a template goes through e() or one of the formatters below.
 */

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Url;

if (!function_exists('e')) {
    /** HTML-escape for text nodes and attribute values. */
    function e(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('url')) {
    function url(string $path): string
    {
        return Url::path($path);
    }
}

if (!function_exists('asset')) {
    function asset(string $path): string
    {
        return Url::asset($path);
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string
    {
        return Csrf::field();
    }
}

if (!function_exists('can')) {
    function can(string $permission): bool
    {
        return Auth::can($permission);
    }
}

if (!function_exists('old')) {
    /**
     * Repopulates a form field after a failed validation pass.
     *
     * @param array<string,mixed> $old
     */
    function old(array $old, string $key, mixed $fallback = ''): string
    {
        $value = $old[$key] ?? $fallback;
        if (is_array($value) || is_object($value)) {
            return '';
        }
        return e($value);
    }
}

if (!function_exists('field_error')) {
    /**
     * @param array<string,list<string>> $errors
     */
    function field_error(array $errors, string $key): string
    {
        if (!isset($errors[$key][0])) {
            return '';
        }
        return '<div class="invalid-feedback d-block">' . e($errors[$key][0]) . '</div>';
    }
}

if (!function_exists('has_error')) {
    /** @param array<string,list<string>> $errors */
    function has_error(array $errors, string $key): string
    {
        return isset($errors[$key]) ? ' is-invalid' : '';
    }
}

// ---------------------------------------------------------------------------
// Formatters
// ---------------------------------------------------------------------------

if (!function_exists('money')) {
    /** Indian-format currency without a symbol, e.g. 12,34,567.00 */
    function money(mixed $amount, bool $decimals = true): string
    {
        $value = (float) ($amount ?? 0);
        $formatted = number_format(abs($value), $decimals ? 2 : 0, '.', '');

        // Indian grouping: last 3 digits, then pairs.
        $parts = explode('.', $formatted);
        $integer = $parts[0];
        $fraction = isset($parts[1]) ? '.' . $parts[1] : '';

        if (strlen($integer) > 3) {
            $last3 = substr($integer, -3);
            $rest = substr($integer, 0, -3);
            $rest = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest) ?? $rest;
            $integer = $rest . ',' . $last3;
        }

        return ($value < 0 ? '-' : '') . $integer . $fraction;
    }
}

if (!function_exists('rupees')) {
    function rupees(mixed $amount, bool $decimals = true): string
    {
        return '₹' . money($amount, $decimals);
    }
}

if (!function_exists('fmt_date')) {
    function fmt_date(?string $value, string $format = 'd M Y'): string
    {
        if ($value === null || $value === '' || str_starts_with($value, '0000')) {
            return '—';
        }
        $ts = strtotime($value);
        return $ts === false ? '—' : date($format, $ts);
    }
}

if (!function_exists('fmt_datetime')) {
    function fmt_datetime(?string $value, string $format = 'd M Y, h:i A'): string
    {
        return fmt_date($value, $format);
    }
}

if (!function_exists('fmt_time')) {
    function fmt_time(?string $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }
        $ts = strtotime('1970-01-01 ' . $value);
        return $ts === false ? e($value) : date('h:i A', $ts);
    }
}

if (!function_exists('time_ago')) {
    function time_ago(?string $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }
        $ts = strtotime($value);
        if ($ts === false) {
            return '—';
        }

        $diff = time() - $ts;
        if ($diff < 0) {
            return fmt_date($value);
        }
        if ($diff < 60) {
            return 'just now';
        }
        if ($diff < 3600) {
            $m = (int) floor($diff / 60);
            return $m . ' min' . ($m === 1 ? '' : 's') . ' ago';
        }
        if ($diff < 86400) {
            $h = (int) floor($diff / 3600);
            return $h . ' hour' . ($h === 1 ? '' : 's') . ' ago';
        }
        if ($diff < 2592000) {
            $d = (int) floor($diff / 86400);
            return $d . ' day' . ($d === 1 ? '' : 's') . ' ago';
        }
        return fmt_date($value);
    }
}

if (!function_exists('status_badge')) {
    /** Bootstrap badge markup for a lead status. */
    function status_badge(?string $status): string
    {
        $map = [
            'pending'  => ['Pending',   'badge-pending'],
            'visited'  => ['Visited',   'badge-visited'],
            'promise'  => ['Promise',   'badge-promise'],
            'followup' => ['Follow-up', 'badge-followup'],
            'legal'    => ['Legal',     'badge-legal'],
            'closed'   => ['Closed',    'badge-closed'],
        ];
        [$label, $class] = $map[$status ?? ''] ?? [ucfirst((string) $status), 'badge-pending'];
        return '<span class="lrms-badge ' . $class . '">' . e($label) . '</span>';
    }
}

if (!function_exists('promise_badge')) {
    function promise_badge(?string $status): string
    {
        $map = [
            'pending'   => ['Pending',   'badge-promise'],
            'kept'      => ['Kept',      'badge-visited'],
            'broken'    => ['Broken',    'badge-legal'],
            'cancelled' => ['Cancelled', 'badge-closed'],
        ];
        [$label, $class] = $map[$status ?? ''] ?? [ucfirst((string) $status), 'badge-pending'];
        return '<span class="lrms-badge ' . $class . '">' . e($label) . '</span>';
    }
}

if (!function_exists('yes_no')) {
    function yes_no(mixed $value): string
    {
        return (int) $value === 1
            ? '<span class="text-success fw-semibold">Yes</span>'
            : '<span class="text-muted">No</span>';
    }
}

if (!function_exists('nullable')) {
    /** Renders an em dash for empty values so table cells never look broken. */
    function nullable(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '<span class="text-muted">—</span>';
        }
        return e($value);
    }
}

if (!function_exists('active_nav')) {
    /** Marks the current sidebar item. */
    function active_nav(string $prefix, string $currentPath): string
    {
        if ($prefix === '/dashboard') {
            return $currentPath === '/dashboard' ? ' active' : '';
        }
        return str_starts_with($currentPath, $prefix) ? ' active' : '';
    }
}

if (!function_exists('sort_link')) {
    /** Sortable table header that preserves current filters. */
    function sort_link(string $label, string $column, string $currentSort, string $currentDir): string
    {
        $nextDir = ($currentSort === $column && strtoupper($currentDir) === 'ASC') ? 'desc' : 'asc';
        $icon = '';
        if ($currentSort === $column) {
            $icon = strtoupper($currentDir) === 'ASC' ? ' ▲' : ' ▼';
        }
        $href = Url::withQuery(['sort_by' => $column, 'sort_dir' => $nextDir, 'page' => null]);
        return '<a class="lrms-sort" href="' . e($href) . '">' . e($label) . '<span class="lrms-sort-icon">' . $icon . '</span></a>';
    }
}

if (!function_exists('sort_hidden')) {
    /**
     * Carries the current sort through a filter form.
     *
     * Sorting and filtering used to disagree about which of them owned the URL.
     * `sort_link()` builds on the existing query string, so sorting kept the filters -
     * but a filter form submits only its own fields, so touching any dropdown threw the
     * sort away. Sort the borrower list by outstanding, pick a village, and the list came
     * back sorted by whatever the default was, with nothing to say why.
     *
     * Emitted only when a sort is actually active, so a default-ordered list does not
     * start putting sort_by into its URLs and pinning the order it happens to have today.
     */
    function sort_hidden(string $sortBy, string $sortDir): string
    {
        if (trim($sortBy) === '') {
            return '';
        }

        return '<input type="hidden" name="sort_by" value="' . e($sortBy) . '">'
            . '<input type="hidden" name="sort_dir" value="' . e(strtolower($sortDir) === 'asc' ? 'asc' : 'desc') . '">';
    }
}

if (!function_exists('occupation_label')) {
    function occupation_label(?string $value): string
    {
        $map = [
            'agriculture' => 'Agriculture',
            'dairy'       => 'Dairy',
            'business'    => 'Business',
            'labour'      => 'Labour',
            'service'     => 'Service',
            'others'      => 'Other',
            // The value this column held before the printed form's wording was adopted.
            // No row should still carry it - the migration rewrites them - but a report
            // must not print a dash for an occupation somebody recorded, and this is
            // cheaper than an operator wondering whether the migration finished.
            'job'         => 'Service',
        ];
        return $map[$value ?? ''] ?? '—';
    }
}

if (!function_exists('enum_label')) {
    /**
     * The printable label for a value out of one of the VisitReport option maps.
     *
     * One helper rather than a match in every view, because the printed form, the panel
     * screen and the PDF all have to name a choice the same way. When they disagree,
     * the report on the screen and the report in the file say different things about
     * the same tick box.
     *
     * Falls back to the stored value made readable rather than to a dash: an option
     * removed from the list later is still a fact somebody recorded, and printing "—"
     * over it would quietly erase it.
     *
     * @param array<string,string> $map
     */
    function enum_label(array $map, mixed $value, string $empty = '—'): string
    {
        $key = $value === null ? '' : trim((string) $value);
        if ($key === '') {
            return $empty;
        }
        return $map[$key] ?? ucwords(str_replace('_', ' ', $key));
    }
}


// ---------------------------------------------------------------------------
// Icons
//
// Inline SVGs (Feather-style 24x24 stroke paths, hand-written) so the panel has
// no icon-font or icon-library dependency and works offline.
// ---------------------------------------------------------------------------

if (!function_exists('icon')) {
    function icon(string $name, string $class = ''): string
    {
        static $paths = null;

        if ($paths === null) {
            $paths = [
                'dashboard'  => '<rect x="3" y="3" width="7" height="9" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/><rect x="14" y="12" width="7" height="9" rx="1"/><rect x="3" y="16" width="7" height="5" rx="1"/>',
                'users'      => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
                'user'       => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
                'branch'     => '<path d="M3 21h18"/><path d="M5 21V7l7-4 7 4v14"/><path d="M9 21v-6h6v6"/><path d="M9 10h.01M15 10h.01"/>',
                'customers'  => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/>',
                'upload'     => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M17 8l-5-5-5 5"/><path d="M12 3v12"/>',
                'download'   => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5 5 5-5"/><path d="M12 15V3"/>',
                'reports'    => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M8 13h8M8 17h5"/>',
                'chart'      => '<path d="M3 3v18h18"/><rect x="7" y="12" width="3" height="6"/><rect x="12" y="8" width="3" height="10"/><rect x="17" y="5" width="3" height="13"/>',
                'clipboard'  => '<path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1"/><path d="M9 12h6M9 16h4"/>',
                'shield'     => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
                'settings'   => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.6 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.6 1.65 1.65 0 0 0 10 3.09V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9c.14.35.4.65.73.85.28.17.6.26.93.26H21a2 2 0 0 1 0 4h-.09c-.66 0-1.26.39-1.51 1z"/>',
                'logs'       => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M8 12h8M8 16h8M8 8h2"/>',
                'database'   => '<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/>',
                'bell'       => '<path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/>',
                'search'     => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/>',
                'logout'     => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5"/><path d="M21 12H9"/>',
                'check'      => '<path d="M20 6 9 17l-5-5"/>',
                'check-circle' => '<circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/>',
                'x'          => '<path d="M18 6 6 18M6 6l12 12"/>',
                'alert'      => '<path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><path d="M12 9v4M12 17h.01"/>',
                'info'       => '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/>',
                'plus'       => '<path d="M12 5v14M5 12h14"/>',
                'edit'       => '<path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.12 2.12 0 0 1 3 3L12 15l-4 1 1-4z"/>',
                'trash'      => '<path d="M3 6h18M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>',
                'key'        => '<path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3"/>',
                'lock'       => '<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
                'unlock'     => '<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 9.9-1"/>',
                'refresh'    => '<path d="M23 4v6h-6M1 20v-6h6"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/>',
                'swap'       => '<path d="M17 1l4 4-4 4"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><path d="M7 23l-4-4 4-4"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/>',
                'handshake'  => '<path d="m11 17 2 2a1 1 0 1 0 3-3"/><path d="m14 14 2.5 2.5a1 1 0 1 0 3-3l-3.88-3.88a3 3 0 0 0-4.24 0l-.88.88a1 1 0 1 1-3-3l2.81-2.81a5.79 5.79 0 0 1 7.06-.87l.47.28a2 2 0 0 0 1.42.25L21 4"/><path d="m21 3 1 11h-2"/><path d="M3 3 2 14l6.5 6.5a1 1 0 1 0 3-3"/><path d="M3 4h8"/>',
                'note'       => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/>',
                'calendar'   => '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
                'clock'      => '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
                'phone'      => '<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.9.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z"/>',
                'home'       => '<path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><path d="M9 22V12h6v10"/>',
                'image'      => '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/>',
                'map-pin'    => '<path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0z"/><circle cx="12" cy="10" r="3"/>',
                'file'       => '<path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><path d="M13 2v7h7"/>',
                'pen'        => '<path d="M12 19l7-7 3 3-7 7-3-3z"/><path d="M18 13l-1.5-7.5L2 2l3.5 14.5L13 18l5-5z"/><path d="M2 2l7.586 7.586"/><circle cx="11" cy="11" r="2"/>',
                'money'      => '<rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="2.5"/><path d="M6 12h.01M18 12h.01"/>',
                'menu'       => '<path d="M3 12h18M3 6h18M3 18h18"/>',
                'sun'        => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"/>',
                'moon'       => '<path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>',
                'chevron-right' => '<path d="m9 18 6-6-6-6"/>',
                'chevron-left'  => '<path d="m15 18-6-6 6-6"/>',
                'print'      => '<path d="M6 9V2h12v7"/><rect x="6" y="14" width="12" height="8"/><path d="M6 18H4a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v3a2 2 0 0 1-2 2h-2"/>',
                'excel'      => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="m9 13 6 6M15 13l-6 6"/>',
                'pdf'        => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M9 15h1.5a1.5 1.5 0 0 0 0-3H9v6M14 18v-6h1a2 2 0 0 1 0 4h-1"/>',
                'filter'     => '<path d="M22 3H2l8 9.46V19l4 2v-8.54z"/>',
                'eye'        => '<path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/>',
                'inbox'      => '<path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/>',
                'send'       => '<path d="M22 2 11 13"/><path d="M22 2l-7 20-4-9-9-4z"/>',
                'shield-check' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/>',
                'trending-up' => '<path d="m23 6-9.5 9.5-5-5L1 18"/><path d="M17 6h6v6"/>',
                'village'    => '<path d="M3 21h18"/><path d="M4 21V10l4-3 4 3v11"/><path d="M12 21v-7l4-3 4 3v8"/><path d="M7 14h2M15 17h2"/>',
                'external'   => '<path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><path d="M15 3h6v6"/><path d="M10 14 21 3"/>',
            ];
        }

        $body = $paths[$name] ?? $paths['note'];
        $classAttr = $class === '' ? '' : ' class="' . e($class) . '"';

        return '<svg' . $classAttr . ' viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor"'
            . ' stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
            . $body . '</svg>';
    }
}


if (!function_exists('geo_source_badge')) {
    /**
     * The pill that says how a photograph reached the system.
     *
     * A camera capture and a gallery pick are not the same evidence, and on screen
     * they used to be indistinguishable - both rendered as a thumbnail with a type
     * label. A gallery image could have been taken anywhere, on any day, by anyone,
     * which is the whole reason the column exists.
     *
     * Shared by the visit report and the borrower profile so the two cannot end up
     * describing the same photograph differently.
     */
    function geo_source_badge(string $source): string
    {
        [$text, $style, $title] = match ($source) {
            'camera'  => [
                'Camera',
                'background:var(--lrms-primary-light);color:var(--lrms-primary)',
                'Taken with the camera during this visit',
            ],
            'gallery' => [
                'Gallery',
                'background:#fff4e5;color:#8a5a00',
                'Chosen from the phone gallery - it could have been taken anywhere, on any day',
            ],
            default   => [
                'Source not recorded',
                'background:var(--lrms-bg);color:var(--lrms-muted)',
                'Filed by an app build that did not report where the image came from',
            ],
        };

        return sprintf(
            '<span title="%s" style="%s;font-size:.625rem;font-weight:700;text-transform:uppercase;'
            . 'letter-spacing:.04em;padding:2px 6px;border-radius:999px;white-space:nowrap">%s</span>',
            e($title),
            $style,
            e($text)
        );
    }
}


if (!function_exists('is_agent')) {
    /**
     * Whether the signed-in user is a BC/DC agent.
     *
     * Used by the navigation, which has to mirror the `allowAgent` flags on the
     * controllers. A permission an agent holds is not the same thing as a screen an
     * agent can open: they hold `visits.view` for the app's benefit, while the panel's
     * visit screens are not scoped to a single agent and so stay closed to them. A link
     * to a page that refuses you is worse than no link.
     */
    function is_agent(): bool
    {
        return Auth::isAgent();
    }
}
