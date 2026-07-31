<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Logger;
use App\Core\Notifier;
use App\Core\Request;
use App\Core\Settings;

/**
 * DB-driven settings. Values take effect on the next request with no file edit
 * and no re-upload, which is the requirement for SMTP / SMS / Maps / Firebase
 * and the published app version.
 */
final class SettingsController extends Controller
{
    /** Groups rendered as tabs, in order. */
    private const GROUP_LABELS = [
        'general'       => 'General',
        'security'      => 'Security',
        'sms'           => 'SMS Gateway',
        'smtp'          => 'Email (SMTP)',
        'integrations'  => 'Integrations',
        'notifications' => 'Notifications',
        'backup'        => 'Backup',
    ];

    public function index(Request $request): void
    {
        $this->guard($request, 'settings.view');

        if ($request->isPost()) {
            Auth::requirePermissionPanel('settings.update', '/settings');
            $this->save($request);
        }

        $this->view($request, 'settings/index', [
            'title'       => 'Settings',
            'grouped'     => Settings::grouped(),
            'groupLabels' => self::GROUP_LABELS,
            'missing'     => Settings::missingRequired(),
            'canUpdate'   => Auth::can('settings.update'),
            'status'      => [
                'sms'   => Notifier::smsConfigured(),
                'smtp'  => Notifier::smtpConfigured(),
                'push'  => Notifier::pushConfigured(),
                'zip'   => class_exists(\ZipArchive::class),
                'gd'    => extension_loaded('gd') || extension_loaded('imagick'),
                'curl'  => function_exists('curl_init'),
                'exec'  => function_exists('exec'),
            ],
        ]);
    }

    private function save(Request $request): never
    {
        $rows = Database::instance()->all('SELECT setting_key, setting_value, is_secret, input_type FROM settings');

        $updates = [];
        $changedKeys = [];

        foreach ($rows as $row) {
            $key = (string) $row['setting_key'];
            $isSecret = (int) $row['is_secret'] === 1;
            $currentValue = $row['setting_value'] === null ? '' : (string) $row['setting_value'];

            // Toggles never appear in the payload when unchecked.
            if ((string) $row['input_type'] === 'toggle') {
                $newValue = $request->bool($key) ? '1' : '0';
            } elseif (!$request->has($key)) {
                continue;
            } else {
                $newValue = $request->str($key);
            }

            // A blank secret means "leave unchanged" - the form never echoes the
            // stored value back, so submitting an empty box must not wipe a key.
            if ($isSecret && $newValue === '') {
                continue;
            }

            if ($newValue === $currentValue) {
                continue;
            }

            $updates[$key] = $newValue;
            $changedKeys[] = $key;
        }

        if ($updates === []) {
            $this->back('/settings', 'info', 'No changes to save.');
        }

        Settings::updateMany($updates, Auth::id());

        // Values are not written to the audit log: several are secrets. The list
        // of changed keys is enough for the trail (Logger also redacts by name).
        Logger::audit(
            'update',
            'settings',
            null,
            null,
            ['changed_keys' => implode(', ', $changedKeys)],
            sprintf('Updated %d setting(s): %s', count($changedKeys), implode(', ', $changedKeys))
        );

        $this->back('/settings', 'success', sprintf(
            '%d setting(s) saved. Changes apply immediately.',
            count($changedKeys)
        ));
    }
}
