<?php
/**
 * Flash messages.
 *
 * @var array<string,list<string>> $flash type => messages
 */

$icons = [
    'success' => 'check-circle',
    'danger'  => 'alert',
    'warning' => 'alert',
    'info'    => 'info',
];

foreach (($flash ?? []) as $type => $messages) {
    $class = in_array($type, ['success', 'danger', 'warning', 'info'], true) ? $type : 'info';

    foreach ($messages as $message) {
        printf(
            '<div class="alert alert-%s alert-dismissible fade show" role="alert"%s>%s<div class="flex-grow-1">%s</div>'
            . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>',
            e($class),
            $class === 'success' ? ' data-auto-dismiss="6000"' : '',
            icon($icons[$class] ?? 'info'),
            // Messages are composed server-side and may contain <strong>/<br> for
            // import summaries, so a narrow tag allow-list is applied instead of
            // full escaping. Any user-derived value inside is escaped at source.
            strip_tags($message, '<strong><b><br><ul><li><code><a>')
        );
    }
}
