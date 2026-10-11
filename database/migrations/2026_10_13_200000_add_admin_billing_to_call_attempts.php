<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A call is also charged to the Admin the caller belongs to (at the rate the Super Admin gave that Admin).
        Schema::table('call_attempts', function (Blueprint $t) {
            $t->unsignedBigInteger('admin_id')->nullable()->after('billed_at');
            $t->unsignedInteger('admin_pulses')->default(0)->after('admin_id');
            $t->decimal('admin_cost', 14, 4)->default(0)->after('admin_pulses');
        });
        Schema::table('user_transactions', function (Blueprint $t) {
            $t->foreignId('call_attempt_id')->nullable()->after('user_id')->constrained('call_attempts')->nullOnDelete();
            $t->unique('call_attempt_id'); // a call is charged to the Admin at most once
        });
    }

    public function down(): void
    {
        Schema::table('user_transactions', function (Blueprint $t) {
            $t->dropForeign(['call_attempt_id']);
            $t->dropUnique(['call_attempt_id']);
            $t->dropColumn('call_attempt_id');
        });
        Schema::table('call_attempts', fn (Blueprint $t) => $t->dropColumn(['admin_id', 'admin_pulses', 'admin_cost']));
    }
};
