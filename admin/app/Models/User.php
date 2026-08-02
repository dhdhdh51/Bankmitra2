<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Crypto;
use App\Core\Database;
use App\Core\Paginator;

final class User
{
    public const SORTABLE = ['employee_code', 'name', 'status', 'created_at', 'last_login_at'];

    public static function find(int $id): ?array
    {
        $row = Database::instance()->first(
            'SELECT u.*, r.slug AS role_slug, r.display_name AS role_name,
                    b.name AS branch_name, b.branch_code
               FROM users u
               JOIN roles r ON r.id = u.role_id
               LEFT JOIN branches b ON b.id = u.branch_id
              WHERE u.id = ? LIMIT 1',
            [$id]
        );

        return $row === null ? null : self::withDecryptedPii($row);
    }

    /**
     * Decrypts a BC's Aadhaar/PAN for the edit form, and drops the ciphertext columns
     * from the array so they are never handed to a view or an audit log by accident -
     * the same discipline Customer::withDecryptedPii() and VisitReport apply to the
     * same two fields elsewhere in this codebase.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function withDecryptedPii(array $row): array
    {
        $row['aadhaar'] = Crypto::decrypt($row['aadhaar_enc'] ?? null);
        $row['pan'] = Crypto::decrypt($row['pan_enc'] ?? null);
        unset($row['aadhaar_enc'], $row['pan_enc']);

        return $row;
    }

    /**
     * Agents belonging to a branch (or all branches for a super admin).
     * Used to populate the "assign to agent" pickers.
     *
     * @return list<array<string,mixed>>
     */
    public static function agents(?int $branchId = null): array
    {
        $sql = "SELECT u.id, u.name, u.employee_code, u.bc_code, u.branch_id, b.name AS branch_name
                  FROM users u
                  JOIN roles r ON r.id = u.role_id
                  LEFT JOIN branches b ON b.id = u.branch_id
                 WHERE r.slug = 'agent' AND u.status = 'active'";
        $params = [];

        if ($branchId !== null) {
            $sql .= ' AND u.branch_id = ?';
            $params[] = $branchId;
        }

        $sql .= ' ORDER BY u.name ASC';

        return Database::instance()->all($sql, $params);
    }

    /** @return list<array<string,mixed>> */
    public static function roleOptions(bool $includeSuperAdmin): array
    {
        $sql = 'SELECT id, slug, display_name FROM roles';
        if (!$includeSuperAdmin) {
            $sql .= " WHERE slug <> 'super_admin'";
        }
        $sql .= ' ORDER BY id ASC';

        return Database::instance()->all($sql);
    }

    public static function paginate(
        string $search,
        ?int $roleId,
        ?int $branchId,
        string $status,
        string $sortBy,
        string $sortDir,
        int $page,
        int $perPage
    ): Paginator {
        $where = ['1 = 1'];
        $params = [];

        if ($search !== '') {
            // Mobile is encrypted, so an exact mobile search goes through the HMAC.
            $mobileHash = Crypto::searchHash($search);
            $where[] = '(u.name LIKE ? OR u.employee_code LIKE ? OR u.email LIKE ? OR u.bc_code LIKE ? OR u.mobile_hash = ?)';
            $like = '%' . $search . '%';
            array_push($params, $like, $like, $like, $like, $mobileHash ?? '');
        }
        if ($roleId !== null) {
            $where[] = 'u.role_id = ?';
            $params[] = $roleId;
        }
        if ($branchId !== null) {
            $where[] = 'u.branch_id = ?';
            $params[] = $branchId;
        }
        if ($status !== '') {
            $where[] = 'u.status = ?';
            $params[] = $status;
        }

        $clause = implode(' AND ', $where);
        $orderColumn = in_array($sortBy, self::SORTABLE, true) ? $sortBy : 'name';
        $direction = strtoupper($sortDir) === 'ASC' ? 'ASC' : 'DESC';

        return Paginator::fromQuery(
            "SELECT COUNT(*) FROM users u WHERE {$clause}",
            "SELECT u.id, u.employee_code, u.name, u.email, u.mobile_masked, u.bc_code, u.designation,
                    u.status, u.must_change_password, u.last_login_at, u.created_at,
                    r.display_name AS role_name, r.slug AS role_slug,
                    b.name AS branch_name, b.branch_code,
                    (SELECT COUNT(*) FROM loan_accounts la WHERE la.assigned_agent_id = u.id) AS assigned_leads
               FROM users u
               JOIN roles r ON r.id = u.role_id
               LEFT JOIN branches b ON b.id = u.branch_id
              WHERE {$clause}
              ORDER BY u.`{$orderColumn}` {$direction}, u.id DESC",
            $params,
            $page,
            $perPage
        );
    }

    /**
     * @param array<string,mixed> $data Plain values; mobile/password/Aadhaar/PAN are
     *                                   encoded here. $data may still carry
     *                                   'aadhaar'/'pan' plain keys, which are consumed
     *                                   and removed rather than inserted as columns
     *                                   that do not exist.
     */
    public static function create(array $data, string $plainPassword, ?string $plainMobile): int
    {
        $data['password_hash'] = password_hash($plainPassword, PASSWORD_BCRYPT);
        $data += self::mobileColumns($plainMobile);
        $data += self::piiColumns($data);
        unset($data['aadhaar'], $data['pan']);

        return Database::instance()->insert('users', $data);
    }

    /** @param array<string,mixed> $data */
    public static function update(int $id, array $data, ?string $plainMobile = null, bool $touchMobile = false): void
    {
        if ($touchMobile) {
            $data += self::mobileColumns($plainMobile);
        }
        $data += self::piiColumns($data);
        unset($data['aadhaar'], $data['pan']);

        Database::instance()->update('users', $data, ['id' => $id]);
    }

    public static function setPassword(int $id, string $plainPassword, bool $forceChange): void
    {
        Database::instance()->update('users', [
            'password_hash'        => password_hash($plainPassword, PASSWORD_BCRYPT),
            'must_change_password' => $forceChange ? 1 : 0,
            'failed_attempts'      => 0,
            'locked_until'         => null,
        ], ['id' => $id]);
    }

    public static function setStatus(int $id, string $status): void
    {
        Database::instance()->update('users', ['status' => $status], ['id' => $id]);
    }

    /**
     * @return array{ok:bool,reason:string}
     */
    public static function deletable(int $id): array
    {
        $db = Database::instance();

        $leads = (int) $db->scalar('SELECT COUNT(*) FROM loan_accounts WHERE assigned_agent_id = ?', [$id]);
        if ($leads > 0) {
            return [
                'ok'     => false,
                'reason' => "{$leads} lead(s) are still assigned to this user. Reassign them first, or suspend the account instead.",
            ];
        }

        $visits = (int) $db->scalar('SELECT COUNT(*) FROM visit_reports WHERE agent_id = ?', [$id]);
        if ($visits > 0) {
            return [
                'ok'     => false,
                'reason' => "This user has {$visits} visit report(s). Visit history is append-only, so the account can be suspended but not deleted.",
            ];
        }

        return ['ok' => true, 'reason' => ''];
    }

    public static function delete(int $id): void
    {
        Database::instance()->delete('users', ['id' => $id]);
    }

    public static function decryptMobile(?array $user): ?string
    {
        if ($user === null) {
            return null;
        }
        return Crypto::decrypt($user['mobile_enc'] ?? null);
    }

    /**
     * @return array<string,string|null>
     */
    public static function mobileColumns(?string $plainMobile): array
    {
        if ($plainMobile === null || trim($plainMobile) === '') {
            return ['mobile_enc' => null, 'mobile_hash' => null, 'mobile_masked' => null];
        }
        return [
            'mobile_enc'    => Crypto::encrypt($plainMobile),
            'mobile_hash'   => Crypto::searchHash($plainMobile),
            'mobile_masked' => Crypto::maskMobile($plainMobile),
        ];
    }

    /**
     * Aadhaar/PAN, encrypted at rest the same way as the mobile number above and as
     * a borrower's own Aadhaar (Customer::piiColumns()) - plaintext never reaches the
     * `users` table, only a ciphertext, a keyed HMAC for exact-match lookup, and a
     * masked value the form and the user list can show without decrypting anything.
     *
     * Reads 'aadhaar'/'pan' out of $data (the plain values the controller collected
     * from the request) rather than taking them as separate parameters: create() and
     * update() both already assemble one $data array for every other field, and a
     * third pair of parameters here would be two more places for the caller to get
     * the argument order wrong.
     *
     * @param array<string,mixed> $data
     * @return array<string,string|null>
     */
    private static function piiColumns(array $data): array
    {
        $columns = [];

        if (array_key_exists('aadhaar', $data)) {
            $aadhaar = $data['aadhaar'] === null ? null : trim((string) $data['aadhaar']);
            $columns += $aadhaar === null || $aadhaar === ''
                ? ['aadhaar_enc' => null, 'aadhaar_hash' => null, 'aadhaar_masked' => null]
                : [
                    'aadhaar_enc'    => Crypto::encrypt($aadhaar),
                    'aadhaar_hash'   => Crypto::searchHash($aadhaar),
                    'aadhaar_masked' => Crypto::maskAadhaar($aadhaar),
                ];
        }

        if (array_key_exists('pan', $data)) {
            $pan = $data['pan'] === null ? null : trim((string) $data['pan']);
            $columns += $pan === null || $pan === ''
                ? ['pan_enc' => null, 'pan_hash' => null, 'pan_masked' => null]
                : [
                    'pan_enc'    => Crypto::encrypt($pan),
                    'pan_hash'   => Crypto::searchHash($pan),
                    'pan_masked' => Crypto::maskPan($pan),
                ];
        }

        return $columns;
    }

    public static function countByRole(string $roleSlug, ?int $branchId = null): int
    {
        $sql = 'SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id WHERE r.slug = ?';
        $params = [$roleSlug];

        if ($branchId !== null) {
            $sql .= ' AND u.branch_id = ?';
            $params[] = $branchId;
        }

        return (int) Database::instance()->scalar($sql, $params);
    }

    /** True when the employee code is free (optionally ignoring one row). */
    public static function employeeCodeAvailable(string $code, ?int $ignoreId = null): bool
    {
        if ($ignoreId !== null) {
            return Database::instance()->scalar(
                'SELECT 1 FROM users WHERE employee_code = ? AND id <> ? LIMIT 1',
                [$code, $ignoreId]
            ) === null;
        }
        return Database::instance()->scalar(
            'SELECT 1 FROM users WHERE employee_code = ? LIMIT 1',
            [$code]
        ) === null;
    }

    /** Registers/refreshes an FCM device token for push. */
    public static function registerDeviceToken(int $userId, string $token, string $appVersion): void
    {
        $db = Database::instance();

        $existing = $db->first('SELECT id, user_id FROM device_tokens WHERE token = ? LIMIT 1', [$token]);

        if ($existing !== null) {
            $db->query(
                'UPDATE device_tokens SET user_id = ?, app_version = ?, last_seen_at = NOW() WHERE id = ?',
                [$userId, $appVersion, (int) $existing['id']]
            );
            return;
        }

        $db->insert('device_tokens', [
            'user_id'      => $userId,
            'token'        => $token,
            'platform'     => 'android',
            'app_version'  => $appVersion,
            'last_seen_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
