<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Subscription extends Model
{
    protected $fillable = [
        'user_id',
        'plan_id',
        'status',
        'expires_at',
        'notes',
        'created_by_admin_id',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function createdByAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_admin_id');
    }

    /**
     * Is this subscription currently usable?
     */
    public function isActive(): bool
    {
        if ($this->status === 'suspended' || $this->status === 'expired') {
            return false;
        }

        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return false;
        }

        return true;
    }

    /**
     * Friendly status label for UI.
     */
    public function statusLabel(): string
    {
        if ($this->status === 'suspended') return 'Suspended';
        if ($this->status === 'expired')   return 'Expired';
        if ($this->expires_at?->isPast())  return 'Expired';
        if ($this->status === 'trial')     return 'Trial';
        return 'Active';
    }

    public function scopeActive($query)
    {
        return $query->whereIn('status', ['active', 'trial'])
                     ->where(fn ($q) => $q->whereNull('expires_at')
                                          ->orWhere('expires_at', '>', now()));
    }
}
