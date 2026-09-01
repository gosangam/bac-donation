<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use App\Support\Money;

class Plan extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'gateway_plan_ids' => 'array',
        'is_active' => 'boolean',
    ];

    public function subscriptions()
    {
        return $this->hasMany(Subscription::class);
    }

    /** The plan's id inside one gateway, or null if it has not been created there. */
    public function gatewayPlanId(string $gateway): ?string
    {
        return $this->gateway_plan_ids[$gateway] ?? null;
    }

    public function getAmountFormattedAttribute(): string
    {
        return Money::format($this->amount, $this->currency);
    }

    public function getCadenceAttribute(): string
    {
        $one = ['daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly', 'yearly' => 'Yearly'];

        if ($this->interval_count === 1) {
            return $one[$this->interval] ?? ucfirst($this->interval);
        }

        return "Every {$this->interval_count} ".Str::plural(rtrim($this->interval, 'ly'), 2);
    }
}
