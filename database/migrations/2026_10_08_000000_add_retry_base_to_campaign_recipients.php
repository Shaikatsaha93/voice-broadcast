<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaign_recipients', function (Blueprint $t) {
            // attempts_count at the time of the last manual retry; gives the recipient a fresh max_attempts budget.
            $t->unsignedTinyInteger('retry_base')->default(0)->after('attempts_count');
        });
    }

    public function down(): void
    {
        Schema::table('campaign_recipients', fn (Blueprint $t) => $t->dropColumn('retry_base'));
    }
};
