<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Prepaid balance per DID, charged per pulse of answered call time.
        Schema::table('dids', function (Blueprint $t) {
            $t->decimal('balance', 14, 4)->default(0)->after('max_concurrent_calls');
            $t->decimal('rate_per_pulse', 10, 4)->default(0)->after('balance'); // 0 = free / not billed
            $t->unsignedSmallInteger('pulse_seconds')->default(60)->after('rate_per_pulse');
        });

        Schema::table('call_attempts', function (Blueprint $t) {
            $t->unsignedInteger('pulses')->default(0)->after('billsec');
            $t->decimal('cost', 14, 4)->default(0)->after('pulses');
            $t->timestamp('billed_at')->nullable()->after('cost');
        });

        // Why a RUNNING campaign is not dialing (e.g. INSUFFICIENT_BALANCE); cleared when calls flow again.
        Schema::table('campaigns', function (Blueprint $t) {
            $t->string('blocked_reason', 40)->nullable()->after('rejection_reason');
        });

        Schema::create('did_transactions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('did_id')->constrained()->cascadeOnDelete();
            $t->foreignId('call_attempt_id')->nullable()->constrained('call_attempts')->nullOnDelete();
            $t->string('type', 20); // opening | topup | deduct | charge
            $t->decimal('amount', 14, 4); // signed: + credit, - debit
            $t->decimal('balance_after', 14, 4);
            $t->string('note', 190)->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['did_id', 'id']);
            $t->unique('call_attempt_id'); // a call is charged at most once
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('did_transactions');
        Schema::table('campaigns', fn (Blueprint $t) => $t->dropColumn('blocked_reason'));
        Schema::table('call_attempts', fn (Blueprint $t) => $t->dropColumn(['pulses', 'cost', 'billed_at']));
        Schema::table('dids', fn (Blueprint $t) => $t->dropColumn(['balance', 'rate_per_pulse', 'pulse_seconds']));
    }
};
