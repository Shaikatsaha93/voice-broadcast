<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Longest the call may run, set when its credit is reserved. The call is hung up at this limit so the balance never goes below 0.
        Schema::table('call_attempts', function (Blueprint $t) {
            $t->unsignedInteger('max_seconds')->nullable()->after('billsec');
        });
    }

    public function down(): void
    {
        Schema::table('call_attempts', fn (Blueprint $t) => $t->dropColumn('max_seconds'));
    }
};
