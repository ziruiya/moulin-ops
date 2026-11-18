<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

class LifecycleRecord extends Model
{
    protected $table = 'component_lifecycle_records';

    protected $guarded = [];

    protected $casts = ['verified_at' => 'datetime'];

    public static function statusForPhase(string $phase): string
    {
        return match ($phase) {
            'Active' => 'ACTIVE',
            'Security Only' => 'SECURITY_ONLY',
            'Extended' => 'EXTENDED_SUPPORT',
            'EOL' => 'EOL',
            default => 'UNKNOWN',
        };
    }

    public function hasEndedSupport(CarbonInterface $date): bool
    {
        return ! in_array($this->lifecycle_status, ['STALE', 'CONFLICT'], true)
            && ($this->support_phase === 'EOL'
                || ($this->date_precision === 'DAY' && $this->eol_date && $this->eol_date < $date->toDateString()));
    }

    public function endsSupportSoon(CarbonInterface $date, int $warningDays): bool
    {
        return $this->date_precision === 'DAY'
            && filled($this->eol_date)
            && ! in_array($this->lifecycle_status, ['STALE', 'CONFLICT'], true)
            && ! $this->hasEndedSupport($date)
            && $this->eol_date <= $date->copy()->addDays($warningDays)->toDateString();
    }
}
