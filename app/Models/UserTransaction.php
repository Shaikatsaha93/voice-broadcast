<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One line of an Admin account's balance ledger. */
class UserTransaction extends Model
{
    public $timestamps = false;

    protected $fillable = ['user_id', 'call_attempt_id', 'type', 'amount', 'balance_after', 'note', 'created_by', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
