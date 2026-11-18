<?php

namespace App\Models;

use App\Services\SettingsService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AssetComponent extends Model
{
    protected $guarded = [];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function lifecycleRecords(): HasMany
    {
        return $this->hasMany(LifecycleRecord::class, 'component_id');
    }

    public function currentLifecycle(): HasOne
    {
        return $this->hasOne(LifecycleRecord::class, 'component_id')->latestOfMany('verified_at');
    }

    public function verifiedLifecycle(): ?LifecycleRecord
    {
        $record = $this->currentLifecycle;

        return $record && filled($this->version) && $record->version === $this->version
            && $record->product_name === $this->product_name && filled($record->source_name) && filled($record->source_url)
            ? $record : null;
    }

    /**
     * @param  array{data_review_days: int, eol_warning_days: int, restore_review_days: int}|null  $rules
     */
    public function lifecycleLabel(?array $rules = null): string
    {
        $record = $this->verifiedLifecycle();
        if (! $record) {
            return '未確認';
        }
        if ($record->lifecycle_status === 'CONFLICT') {
            return '情報源が不一致';
        }
        $rules ??= app(SettingsService::class)->rules();
        if ($record->lifecycle_status === 'STALE' || $record->verified_at->lt(now()->subDays($rules['data_review_days']))) {
            return '要再確認';
        }
        $today = today();
        if ($record->hasEndedSupport($today)) {
            return 'サポート終了（EOL）';
        }
        if (blank($record->eol_date)) {
            return '終了日不明';
        }

        $phase = match ($record->support_phase) {
            'Active' => $record->active_support_end && $record->active_support_end < $today->toDateString()
                ? 'セキュリティ修正のみ' : '通常サポート',
            'Security Only' => 'セキュリティ修正のみ',
            'Extended' => '延長サポート',
            default => '未確認',
        };

        return $record->endsSupportSoon($today, $rules['eol_warning_days'])
            ? $phase.'・終了間近' : $phase;
    }
}
