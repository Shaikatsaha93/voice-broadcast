<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CampaignApproval extends Model
{
    protected $fillable = ['campaign_id', 'admin_id', 'decision', 'reason'];
}
