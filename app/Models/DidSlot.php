<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DidSlot extends Model
{
    public $timestamps = false;

    protected $fillable = ['did_id', 'call_attempt_id', 'acquired_at', 'expires_at'];

    protected function casts(): array
    {
        return ['acquired_at' => 'datetime', 'expires_at' => 'datetime'];
    }
}
