<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NumberImport extends Model
{
    protected $fillable = ['campaign_id', 'user_id', 'path', 'status', 'total_rows', 'valid_rows', 'invalid_rows', 'duplicate_rows', 'imported_rows', 'error'];
}
