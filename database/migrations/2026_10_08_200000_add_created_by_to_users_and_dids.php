<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Who created the row. An Admin only sees the users and DIDs they created; Super Admin sees all.
        Schema::table('users', function (Blueprint $t) {
            $t->foreignId('created_by')->nullable()->after('status')->constrained('users')->nullOnDelete();
        });
        Schema::table('dids', function (Blueprint $t) {
            $t->foreignId('created_by')->nullable()->after('trunk')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('dids', function (Blueprint $t) {
            $t->dropConstrainedForeignId('created_by');
        });
        Schema::table('users', function (Blueprint $t) {
            $t->dropConstrainedForeignId('created_by');
        });
    }
};
