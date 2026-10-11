<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Last day an Admin / User account may be used; the account becomes inactive on its own after it.
        Schema::table('users', fn (Blueprint $t) => $t->date('valid_until')->nullable()->after('status'));
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('valid_until'));
    }
};
