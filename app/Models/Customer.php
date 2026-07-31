<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Crypto;
use App\Core\Database;

final class Customer
{
    public static function find(int $id): ?array
    {
        return Database::instance()->first(
            'SELECT c.*, b.name AS branch_name, b.branch_code
               FROM customers c
               JOIN branches b ON b.id = c.branch_id
              WHERE c.id = ? LIMIT 1',
            [$id]
        );
    }

    /** @return array<string,mixed>|null Row with `mobile` and `aadhaar` decrypted. */
    public static function findWithPii(int $id): ?array
    {
        $row = self::find($id);
        if ($row === null) {
            return null;
        }

        $row['mobile'] = Crypto::decrypt($row['mobile_enc'] ?? null);
        $row['aadhaar'] = Crypto::decrypt($row['aadhaar_enc'] ?? null);
        unset($row['mobile_enc'], $row['aadhaar_enc']);

        return $row;
    }

    /** All loan accounts belonging to one borrower. @return list<array<string,mixed>> */
    public static function loanAccounts(int $customerId): array
    {
        return Database::instance()->all(
            'SELECT la.*, ag.name AS agent_name
               FROM loan_accounts la
               LEFT JOIN users ag ON ag.id = la.assigned_agent_id
              WHERE la.customer_id = ?
              ORDER BY la.created_at DESC',
            [$customerId]
        );
    }

    /**
     * @param array<string,mixed> $data Plain `mobile`/`aadhaar` keys are encoded here.
     */
    public static function create(array $data, ?string $mobile, ?string $aadhaar): int
    {
        $data += self::piiColumns($mobile, $aadhaar);
        return Database::instance()->insert('customers', $data);
    }

    /** @param array<string,mixed> $data */
    public static function update(int $id, array $data, ?string $mobile = null, ?string $aadhaar = null, bool $touchPii = false): void
    {
        if ($touchPii) {
            $data += self::piiColumns($mobile, $aadhaar);
        }
        Database::instance()->update('customers', $data, ['id' => $id]);
    }

    /**
     * Encrypted + hashed + masked triplets for both PII fields.
     *
     * @return array<string,string|null>
     */
    public static function piiColumns(?string $mobile, ?string $aadhaar): array
    {
        $columns = [];

        if ($mobile !== null && trim($mobile) !== '') {
            $columns['mobile_enc'] = Crypto::encrypt($mobile);
            $columns['mobile_hash'] = Crypto::searchHash($mobile);
            $columns['mobile_masked'] = Crypto::maskMobile($mobile);
        } else {
            $columns['mobile_enc'] = null;
            $columns['mobile_hash'] = null;
            $columns['mobile_masked'] = null;
        }

        if ($aadhaar !== null && trim($aadhaar) !== '') {
            $columns['aadhaar_enc'] = Crypto::encrypt($aadhaar);
            $columns['aadhaar_hash'] = Crypto::searchHash($aadhaar);
            $columns['aadhaar_masked'] = Crypto::maskAadhaar($aadhaar);
        } else {
            $columns['aadhaar_enc'] = null;
            $columns['aadhaar_hash'] = null;
            $columns['aadhaar_masked'] = null;
        }

        return $columns;
    }

    /** Exact-match lookup by mobile, used by API search. */
    public static function findByMobile(string $mobile, ?int $branchId = null): ?array
    {
        $hash = Crypto::searchHash($mobile);
        if ($hash === null) {
            return null;
        }

        $sql = 'SELECT * FROM customers WHERE mobile_hash = ?';
        $params = [$hash];

        if ($branchId !== null) {
            $sql .= ' AND branch_id = ?';
            $params[] = $branchId;
        }
        $sql .= ' LIMIT 1';

        return Database::instance()->first($sql, $params);
    }

    public static function findByAadhaar(string $aadhaar, ?int $branchId = null): ?array
    {
        $hash = Crypto::searchHash($aadhaar);
        if ($hash === null) {
            return null;
        }

        $sql = 'SELECT * FROM customers WHERE aadhaar_hash = ?';
        $params = [$hash];

        if ($branchId !== null) {
            $sql .= ' AND branch_id = ?';
            $params[] = $branchId;
        }
        $sql .= ' LIMIT 1';

        return Database::instance()->first($sql, $params);
    }

    public static function countAll(?int $branchId = null): int
    {
        if ($branchId !== null) {
            return (int) Database::instance()->scalar('SELECT COUNT(*) FROM customers WHERE branch_id = ?', [$branchId]);
        }
        return (int) Database::instance()->scalar('SELECT COUNT(*) FROM customers');
    }
}
