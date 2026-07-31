<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Offset paginator shared by every list screen and list endpoint.
 *
 * @template T of array<string,mixed>
 */
final class Paginator
{
    /** @param list<array<string,mixed>> $items */
    public function __construct(
        public readonly array $items,
        public readonly int $total,
        public readonly int $page,
        public readonly int $perPage
    ) {
    }

    /**
     * Runs the count query and the page query as a pair.
     *
     * @param string               $countSql  SELECT COUNT(*) ...
     * @param string               $rowsSql   SELECT ... (without LIMIT/OFFSET)
     * @param array<string|int,mixed> $params  Shared bindings for both queries
     */
    public static function fromQuery(
        string $countSql,
        string $rowsSql,
        array $params,
        int $page,
        int $perPage
    ): self {
        $db = Database::instance();

        $total = (int) $db->scalar($countSql, $params);

        $lastPage = max(1, (int) ceil($total / max(1, $perPage)));
        $page = min(max(1, $page), $lastPage);
        $offset = ($page - 1) * $perPage;

        // LIMIT/OFFSET are cast integers, never raw input.
        $sql = $rowsSql . sprintf(' LIMIT %d OFFSET %d', $perPage, $offset);
        $items = $db->all($sql, $params);

        return new self($items, $total, $page, $perPage);
    }

    public function lastPage(): int
    {
        return max(1, (int) ceil($this->total / max(1, $this->perPage)));
    }

    public function from(): int
    {
        return $this->total === 0 ? 0 : (($this->page - 1) * $this->perPage) + 1;
    }

    public function to(): int
    {
        return min($this->total, $this->page * $this->perPage);
    }

    public function hasPrevious(): bool
    {
        return $this->page > 1;
    }

    public function hasNext(): bool
    {
        return $this->page < $this->lastPage();
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    /**
     * Compact page-number window for the pagination control.
     *
     * @return list<int|string> Page numbers with '...' gaps.
     */
    public function window(int $eachSide = 2): array
    {
        $last = $this->lastPage();
        $pages = [];

        for ($i = 1; $i <= $last; $i++) {
            if ($i === 1 || $i === $last || abs($i - $this->page) <= $eachSide) {
                $pages[] = $i;
            } elseif (end($pages) !== '...') {
                $pages[] = '...';
            }
        }

        return $pages;
    }

    /**
     * Pagination meta for API responses.
     *
     * @return array<string,int|bool>
     */
    public function meta(): array
    {
        return [
            'current_page' => $this->page,
            'per_page'     => $this->perPage,
            'total'        => $this->total,
            'last_page'    => $this->lastPage(),
            'from'         => $this->from(),
            'to'           => $this->to(),
            'has_more'     => $this->hasNext(),
        ];
    }
}
