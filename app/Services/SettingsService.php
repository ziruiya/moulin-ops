<?php

namespace App\Services;

use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

class SettingsService
{
    public const DEFAULTS = [
        'data_review_days' => 90,
        'eol_warning_days' => 60,
        'restore_review_days' => 90,
    ];

    public const MIN_DAYS = 1;

    public const MAX_DAYS = 3650;

    private readonly string $path;

    public function __construct(private readonly AuditLogger $audit, ?string $path = null)
    {
        $this->path = $path ?? storage_path('app/system-settings.json');
    }

    /**
     * @return array{data_review_days: int, eol_warning_days: int, restore_review_days: int}
     */
    public function rules(): array
    {
        return $this->decode($this->contents());
    }

    /**
     * @param  array{data_review_days: int, eol_warning_days: int, restore_review_days: int}  $rules
     */
    public function save(array $rules, int $actorId): void
    {
        $lock = fopen($this->path.'.lock', 'c');
        if ($lock === false) {
            throw new RuntimeException('Could not open the system settings lock.');
        }

        try {
            if (! flock($lock, LOCK_EX)) {
                throw new RuntimeException('Could not lock system settings.');
            }

            $previous = $this->contents();
            $updated = json_encode($rules, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT).PHP_EOL;
            $published = false;

            try {
                DB::transaction(function () use ($rules, $actorId, $previous, $updated, &$published): void {
                    $this->audit->logFor('SystemSetting', 1, 'system_settings_updated', $this->decode($previous), $rules, $actorId);
                    File::replace($this->path, $updated);
                    $published = true;
                });
            } catch (Throwable $failure) {
                if ($published) {
                    if ($previous === null) {
                        File::delete($this->path);
                    } else {
                        File::replace($this->path, $previous);
                    }
                }

                throw $failure;
            }
        } finally {
            fclose($lock);
        }
    }

    private function contents(): ?string
    {
        try {
            return File::get($this->path);
        } catch (FileNotFoundException) {
            return null;
        }
    }

    /**
     * @return array{data_review_days: int, eol_warning_days: int, restore_review_days: int}
     */
    private function decode(?string $contents): array
    {
        $stored = json_decode($contents ?? '', true);
        if (! is_array($stored)) {
            return self::DEFAULTS;
        }

        $rules = [];
        foreach (array_keys(self::DEFAULTS) as $key) {
            $value = $stored[$key] ?? null;
            if (! is_int($value) || $value < self::MIN_DAYS || $value > self::MAX_DAYS) {
                return self::DEFAULTS;
            }

            $rules[$key] = $value;
        }

        return $rules;
    }
}
