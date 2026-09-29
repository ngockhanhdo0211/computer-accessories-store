<?php

namespace App\Models;

use App\Enums\MembershipLevel;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /** @var list<string> */
    protected $fillable = [
        'name',
        'email',
        'phone',
        'gender',
        'dob',
        'address',
        'password',
    ];

    /** @var list<string> */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'dob' => 'date',
            'last_login_at' => 'datetime',
            'must_change_password' => 'boolean',
            'membership_spending' => 'integer',
            'role' => UserRole::class,
            'status' => UserStatus::class,
            'current_tier' => MembershipLevel::class,
            'password' => 'hashed',
        ];
    }

    public function isCustomer(): bool
    {
        return $this->role === UserRole::Customer;
    }

    public function isEmployee(): bool
    {
        return $this->role === UserRole::Employee;
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    public function isActive(): bool
    {
        return $this->status === UserStatus::Active;
    }

    public function inventoryTransactions(): HasMany
    {
        return $this->hasMany(InventoryTransaction::class, 'actor_id');
    }

    public function inventoryAdjustmentRequests(): HasMany
    {
        return $this->hasMany(InventoryAdjustmentRequest::class, 'requested_by');
    }

    public function cartItems(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }

    public function updatedShippingRates(): HasMany
    {
        return $this->hasMany(ShippingRate::class, 'updated_by');
    }

    public function paymentAttempts(): HasMany
    {
        return $this->hasMany(PaymentAttempt::class);
    }
}
