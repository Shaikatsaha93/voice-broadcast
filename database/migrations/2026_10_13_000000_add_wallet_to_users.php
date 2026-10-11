<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Admin accounts: the call charge (per pulse) agreed for them and the balance they were given.
        Schema::table('users', function (Blueprint $t) {
            $t->decimal('rate_per_pulse', 10, 4)->nullable()->after('valid_until');
            $t->unsignedSmallInteger('pulse_seconds')->nullable()->after('rate_per_pulse');
            $t->decimal('balance', 14, 4)->nullable()->after('pulse_seconds');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['rate_per_pulse', 'pulse_seconds', 'balance']));
    }
};
