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

    /**
     * The price in a given currency, or null if this plan is not offered in it.
     *
     * `amount`/`currency` hold the domestic price; `amount_usd` is the separate
     * figure foreign donors are billed, which is a pricing decision rather than
     * a live conversion — the two are set independently and neither is derived.
     */
    public function amountFor(string $currency): ?int
    {
        $currency = strtoupper($currency);

        if ($currency === strtoupper($this->currency)) {
            return (int) $this->amount;
        }

        if ($currency === 'USD' && $this->amount_usd !== null) {
            return (int) $this->amount_usd;
        }

        return null;
    }

    public function offeredIn(string $currency): bool
    {
        return $this->amountFor($currency) !== null;
    }

    public function amountFormattedIn(string $currency): ?string
    {
        $amount = $this->amountFor($currency);

        return $amount === null ? null : Money::format($amount, strtoupper($currency));
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
