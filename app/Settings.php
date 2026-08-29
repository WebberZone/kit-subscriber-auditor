<?php

declare(strict_types=1);

namespace KitAudit;

final class Settings
{
    /** @var array<string, string> */
    private const DEFAULTS = [
        'inactivity_threshold_days' => '180',
        'min_emails_sent' => '5',
        'batch_size' => '15',
        'dry_run' => '1',
    ];

    public function __construct(private readonly Database $database)
    {
        foreach (self::DEFAULTS as $key => $value) {
            $this->database->execute(
                'INSERT OR IGNORE INTO app_settings (setting_key, setting_value) VALUES (:key, :value)',
                ['key' => $key, 'value' => $value]
            );
        }
    }

    /** @return array<string, int> */
    public function all(): array
    {
        $rows = $this->database->fetchAll('SELECT setting_key, setting_value FROM app_settings');
        $settings = [];
        foreach (self::DEFAULTS as $key => $default) {
            $settings[$key] = (int) $default;
        }
        foreach ($rows as $row) {
            if (array_key_exists($row['setting_key'], $settings)) {
                $settings[$row['setting_key']] = (int) $row['setting_value'];
            }
        }

        return $settings;
    }

    /** @param array<string, mixed> $input */
    public function save(array $input): void
    {
        $threshold = $this->boundedInt($input['inactivity_threshold_days'] ?? 180, 1, 3650);
        $minSent = $this->boundedInt($input['min_emails_sent'] ?? 5, 0, 100000);
        $batchSize = $this->boundedInt($input['batch_size'] ?? 15, 1, 50);
        $dryRun = isset($input['dry_run']) ? 1 : 0;
        $values = [
            'inactivity_threshold_days' => $threshold,
            'min_emails_sent' => $minSent,
            'batch_size' => $batchSize,
            'dry_run' => $dryRun,
        ];
        foreach ($values as $key => $value) {
            $this->database->execute(
                'INSERT INTO app_settings (setting_key, setting_value) VALUES (:key, :value)
                 ON CONFLICT(setting_key) DO UPDATE SET setting_value = excluded.setting_value',
                ['key' => $key, 'value' => (string) $value]
            );
        }
    }

    private function boundedInt(mixed $value, int $minimum, int $maximum): int
    {
        $value = filter_var($value, FILTER_VALIDATE_INT);
        if ($value === false) {
            return $minimum;
        }

        return max($minimum, min($maximum, (int) $value));
    }
}

