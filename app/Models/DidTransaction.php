<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One line of a DID's balance ledger. */
class DidTransaction extends Model
{
    public $timestamps = false;

    protected $fillable = ['did_id', 'call_attempt_id', 'type', 'amount', 'balance_after', 'note', 'created_by', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function did(): BelongsTo
    {
        return $this->belongsTo(Did::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
