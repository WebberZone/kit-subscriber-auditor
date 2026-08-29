<?php

declare(strict_types=1);

namespace KitAudit;

use DateTimeImmutable;
use DateTimeZone;

final class AuditService
{
    public const VERY_COLD_DAYS = 365;
    public const VERY_COLD_MIN_SENT = 10;
    public const RECENTLY_SUBSCRIBED_DAYS = 30;

    public function __construct(private readonly Database $database)
    {
    }

    /** @return array<string, int|float|string|null> */
    public function dashboardMetrics(array $settings): array
    {
        $metrics = [];
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $inactivityDays = [90, 180, 270, 365];

        $metrics['total_active'] = $this->count('state = :state', ['state' => 'active']);
        foreach ($inactivityDays as $days) {
            $cutoff = $now->modify('-' . $days . ' days')->format('c');
            $metrics['inactive_' . $days] = $this->count(
                "state = :state AND (\n                    (last_opened IS NULL OR last_opened < :cutoff_open)\n                    AND (last_clicked IS NULL OR last_clicked < :cutoff_click)\n                )",
                ['state' => 'active', 'cutoff_open' => $cutoff, 'cutoff_click' => $cutoff]
            );
        }

        $metrics['never_opened'] = $this->count('state = :state AND last_opened IS NULL', ['state' => 'active']);
        $metrics['never_clicked'] = $this->count('state = :state AND last_clicked IS NULL', ['state' => 'active']);
        $recentCutoff = $now->modify('-' . self::RECENTLY_SUBSCRIBED_DAYS . ' days')->format('c');
        $metrics['recently_subscribed'] = $this->count(
            'state = :state AND created_at >= :cutoff',
            ['state' => 'active', 'cutoff' => $recentCutoff]
        );
        $metrics['sends_since_last_open'] = $this->count(
            'state = :state AND COALESCE(sends_since_last_open, 0) > 0',
            ['state' => 'active']
        );
        $metrics['removal_candidates'] = $this->countWhere(
            'state = :removal_state AND ' . $this->removalWhere($settings),
            array_merge(['removal_state' => 'active'], $this->removalParams($settings))
        );
        $metrics['very_cold'] = $this->countWhere(
            'state = :very_cold_state AND ' . $this->veryColdWhere(),
            array_merge(['very_cold_state' => 'active'], $this->veryColdParams())
        );

        return $metrics;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, pages: int}
     */
    public function subscribers(array $filters, array $settings, bool $allRows = false): array
    {
        $where = ['state = :active_state'];
        $parameters = ['active_state' => 'active'];
        $group = (string) ($filters['group'] ?? 'all');

        if ($group === 'removal') {
            $where[] = $this->removalWhere($settings);
            $parameters = array_merge($parameters, $this->removalParams($settings));
        } elseif ($group === 'very-cold') {
            $where[] = $this->veryColdWhere();
            $parameters = array_merge($parameters, $this->veryColdParams());
        } elseif ($group === 'never-opened') {
            $where[] = 'last_opened IS NULL';
        } elseif ($group === 'never-clicked') {
            $where[] = 'last_clicked IS NULL';
        } elseif ($group === 'recent') {
            $where[] = 'created_at >= :recent_cutoff';
            $parameters['recent_cutoff'] = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
                ->modify('-' . self::RECENTLY_SUBSCRIBED_DAYS . ' days')
                ->format('c');
        } elseif ($group === 'sends-since-open') {
            $where[] = 'COALESCE(sends_since_last_open, 0) > 0';
        }

        $search = trim((string) ($filters['q'] ?? ''));
        if ($search !== '') {
            $where[] = '(email_address LIKE :search OR first_name LIKE :search)';
            $parameters['search'] = '%' . $search . '%';
        }

        $sortMap = [
            'email' => 'email_address',
            'created' => 'created_at',
            'last_opened' => 'last_opened',
            'last_clicked' => 'last_clicked',
            'sent' => 'sent',
            'sends_since_open' => 'sends_since_last_open',
            'open_rate' => 'open_rate',
            'click_rate' => 'click_rate',
        ];
        $sortKey = (string) ($filters['sort'] ?? 'created');
        $sort = $sortMap[$sortKey] ?? 'created_at';
        $direction = strtolower((string) ($filters['direction'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
        $whereSql = implode(' AND ', $where);
        $totalRow = $this->database->fetchOne(
            'SELECT COUNT(*) AS total FROM subscribers WHERE ' . $whereSql,
            $parameters
        );
        $total = (int) ($totalRow['total'] ?? 0);
        $perPage = 100;
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($pages, (int) ($filters['page'] ?? 1)));
        $offset = ($page - 1) * $perPage;
        $limitSql = $allRows ? '' : ' LIMIT ' . $perPage . ' OFFSET ' . $offset;

        $rows = $this->database->fetchAll(
            'SELECT * FROM subscribers WHERE ' . $whereSql
            . ' ORDER BY ' . $sort . ' IS NULL ASC, ' . $sort . ' ' . $direction . ', id DESC'
            . $limitSql,
            $parameters
        );

        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    /** @return list<array<string, mixed>> */
    public function removalCandidatesByIds(array $ids, array $settings): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        return $this->database->fetchAll(
            'SELECT * FROM subscribers WHERE state = ? AND id IN (' . $placeholders . ') AND '
            . $this->removalWherePositional() . ' ORDER BY created_at ASC, id ASC',
            array_merge(['active'], $ids, $this->removalParamsPositional($settings))
        );
    }

    public function removalReason(array $settings): string
    {
        return sprintf(
            'No open or click in %d days; subscribed more than %d days; at least %d emails sent.',
            (int) $settings['inactivity_threshold_days'],
            (int) $settings['inactivity_threshold_days'],
            (int) $settings['min_emails_sent']
        );
    }

    /** @return array<string, string|int> */
    public function removalParams(array $settings): array
    {
        $cutoff = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify('-' . (int) $settings['inactivity_threshold_days'] . ' days')
            ->format('c');

        return [
            'removal_cutoff_open' => $cutoff,
            'removal_cutoff_click' => $cutoff,
            'removal_cutoff_created' => $cutoff,
            'removal_min_sent' => (int) $settings['min_emails_sent'],
        ];
    }

    public function removalWhere(array $settings): string
    {
        return '(last_opened IS NULL OR last_opened < :removal_cutoff_open)'
            . ' AND (last_clicked IS NULL OR last_clicked < :removal_cutoff_click)'
            . ' AND created_at < :removal_cutoff_created'
            . ' AND sent >= :removal_min_sent';
    }

    private function removalWherePositional(): string
    {
        return '(last_opened IS NULL OR last_opened < ?)'
            . ' AND (last_clicked IS NULL OR last_clicked < ?)'
            . ' AND created_at < ?'
            . ' AND sent >= ?';
    }

    /** @return list<string|int> */
    private function removalParamsPositional(array $settings): array
    {
        $params = $this->removalParams($settings);
        return [
            $params['removal_cutoff_open'],
            $params['removal_cutoff_click'],
            $params['removal_cutoff_created'],
            $params['removal_min_sent'],
        ];
    }

    /** @return array<string, string|int> */
    private function veryColdParams(): array
    {
        $cutoff = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify('-' . self::VERY_COLD_DAYS . ' days')
            ->format('c');

        return [
            'very_cold_open' => $cutoff,
            'very_cold_click' => $cutoff,
            'very_cold_min_sent' => self::VERY_COLD_MIN_SENT,
        ];
    }

    private function veryColdWhere(): string
    {
        return '(last_opened IS NULL OR last_opened < :very_cold_open)'
            . ' AND (last_clicked IS NULL OR last_clicked < :very_cold_click)'
            . ' AND sent >= :very_cold_min_sent';
    }

    private function count(string $where, array $parameters): int
    {
        return $this->countWhere($where, $parameters);
    }

    private function countWhere(string $where, array $parameters): int
    {
        $row = $this->database->fetchOne('SELECT COUNT(*) AS total FROM subscribers WHERE ' . $where, $parameters);
        return (int) ($row['total'] ?? 0);
    }
}
