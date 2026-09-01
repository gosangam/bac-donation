<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Transaction extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'gateway_payload' => 'array',
        'paid_at' => 'datetime',
        'receipt_emailed_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function subscription()
    {
        return $this->belongsTo(Subscription::class);
    }

    public function scopePaid($query)
    {
        return $query->where('status', 'paid');
    }

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }

    /** Our own reference, generated before the donor reaches any checkout. */
    public static function newReference(): string
    {
        return 'BAC-'.strtoupper(Str::random(12));
    }

    /**
     * Receipt numbers are only assigned when money is actually received, and
     * never reassigned: a receipt number that changes is worse than none.
     * Sequential per Indian financial year (April–March), which is what an
     * auditor expects to see.
     */
    public function assignReceiptNumber(): string
    {
        if ($this->receipt_no) {
            return $this->receipt_no;
        }

        $paidAt = $this->paid_at ?? now();
        $fyStart = $paidAt->month >= 4 ? $paidAt->year : $paidAt->year - 1;
        $fy = $fyStart.'-'.substr((string) ($fyStart + 1), -2);

        // Locked so two concurrent webhooks cannot claim the same number.
        return \DB::transaction(function () use ($fy) {
            $prefix = "BAC/{$fy}/";

            $last = static::where('receipt_no', 'like', $prefix.'%')
                ->lockForUpdate()
                ->orderByDesc('id')
                ->value('receipt_no');

            $next = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;
            $number = $prefix.str_pad((string) $next, 5, '0', STR_PAD_LEFT);

            $this->forceFill(['receipt_no' => $number])->save();

            return $number;
        });
    }

    public function getAmountFormattedAttribute(): string
    {
        return Money::format($this->amount, $this->currency);
    }

    public function getGatewayLabelAttribute(): string
    {
        return ['razorpay' => 'Razorpay', 'stripe' => 'Stripe', 'paypal' => 'PayPal'][$this->gateway]
            ?? ucfirst($this->gateway);
    }
}
