<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id', 'idempotency_key', 'gateway_reference', 'amount', 'purpose',
    'mypay_session_id', 'mypay_payment_url', 'status',
    'webhook_received_at', 'paid_at', 'expires_at',
])]
class PaymentSession extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'webhook_received_at' => 'datetime',
            'paid_at' => 'datetime',
            'expires_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
