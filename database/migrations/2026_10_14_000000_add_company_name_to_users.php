<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The company the account belongs to (optional, shown next to the name in the admin lists).
        Schema::table('users', function (Blueprint $t) {
            $t->string('company_name', 150)->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('company_name'));
    }
};
