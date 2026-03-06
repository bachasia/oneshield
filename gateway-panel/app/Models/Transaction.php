<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transaction extends Model
{
    protected $fillable = [
        'site_id',
        'order_id',
        'amount',
        'currency',
        'gateway',
        'status',
        'gateway_transaction_id',
        'money_site_domain',
        'raw_response',
        'billing_data',
    ];

    protected $casts = [
        'amount'       => 'decimal:2',
        'raw_response' => 'array',
        // billing_data is stored as AES-encrypted JSON
        'billing_data' => 'encrypted:array',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(ShieldSite::class, 'site_id');
    }
}
