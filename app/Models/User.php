<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'name', 'email', 'password', 'phone',
        'address_line1', 'address_line2', 'city', 'state', 'postal_code', 'country', 'pan',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
        ];
    }

    public function subscriptions()
    {
        return $this->hasMany(Subscription::class);
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }

    public function activeSubscriptions()
    {
        return $this->subscriptions()->active();
    }

    /** Single-line address for receipts and gateway checkout prefills. */
    public function getFullAddressAttribute(): string
    {
        return collect([
            $this->address_line1, $this->address_line2, $this->city,
            $this->state, $this->postal_code, $this->country,
        ])->filter()->implode(', ');
    }

    /**
     * is_admin is deliberately NOT mass-assignable, so a stray create($request->all())
     * can never grant admin. That also means $user->update(['is_admin' => true])
     * silently does nothing — use these instead, or `php artisan user:admin`.
     */
    public function promoteToAdmin(): void
    {
        $this->forceFill(['is_admin' => true])->save();
    }

    public function revokeAdmin(): void
    {
        $this->forceFill(['is_admin' => false])->save();
    }

    /** True once we hold everything a receipt and a gateway need. */
    public function hasCompleteDonorProfile(): bool
    {
        return filled($this->name) && filled($this->email) && filled($this->phone)
            && filled($this->address_line1) && filled($this->city)
            && filled($this->postal_code) && filled($this->country);
    }
}
