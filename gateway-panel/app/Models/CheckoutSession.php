<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CheckoutSession extends Model
{
    use HasUuids;

    protected $fillable = [
        'user_id',
        'site_id',
        'gateway',
        'order_ref',
        'amount_minor',
        'currency',
        'amount_display',
        'mode',
        'capture_method',
        'enable_wallets',
        'descriptor',
        'description_format',
        'billing_snapshot',
        'transaction_id',
        'idempotency_key',
        'status',
        'expires_at',
        'completed_at',
        'stripe_payment_intent_id',
        'gateway_transaction_id',
        'meta',
    ];

    protected $casts = [
        'enable_wallets' => 'boolean',
        'amount_minor'   => 'integer',
        'expires_at'     => 'datetime',
        'completed_at'   => 'datetime',
        'meta'           => 'array',
        // billing_snapshot stored as AES-encrypted JSON (same pattern as Transaction)
        'billing_snapshot' => 'encrypted:array',
    ];

    // ── Status constants ──────────────────────────────────────────────────

    const STATUS_CREATED    = 'created';
    const STATUS_PROCESSING = 'processing';
    const STATUS_COMPLETED  = 'completed';
    const STATUS_EXPIRED    = 'expired';
    const STATUS_CANCELLED  = 'cancelled';

    // ── Relationships ─────────────────────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(ShieldSite::class, 'site_id');
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    /**
     * Whether this session is still usable (not expired, not completed/cancelled).
     */
    public function isUsable(): bool
    {
        return in_array($this->status, [self::STATUS_CREATED, self::STATUS_PROCESSING], true)
            && $this->expires_at->isFuture();
    }

    /**
     * Whether this session has already been completed (single-use enforcement).
     */
    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    /**
     * Mark the session as processing (intent has been created/attempted).
     */
    public function markProcessing(): bool
    {
        return $this->update(['status' => self::STATUS_PROCESSING]);
    }

    /**
     * Mark the session as completed and lock it.
     */
    public function markCompleted(string $gatewayTxnId, ?string $stripePaymentIntentId = null): bool
    {
        return $this->update([
            'status'                   => self::STATUS_COMPLETED,
            'completed_at'             => now(),
            'gateway_transaction_id'   => $gatewayTxnId,
            'stripe_payment_intent_id' => $stripePaymentIntentId,
        ]);
    }

    /**
     * Mark the session as expired.
     */
    public function markExpired(): bool
    {
        return $this->update(['status' => self::STATUS_EXPIRED]);
    }

    // ── Scopes ────────────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->whereIn('status', [self::STATUS_CREATED, self::STATUS_PROCESSING])
            ->where('expires_at', '>', now());
    }

    public function scopeExpired($query)
    {
        return $query->where('status', self::STATUS_CREATED)
            ->where('expires_at', '<=', now());
    }
}
