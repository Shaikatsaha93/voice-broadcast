<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dids', function (Blueprint $t) {
            $t->string('did_type', 10)->default('reseller')->after('label'); // reseller | own
            $t->decimal('mrc', 12, 2)->nullable()->after('did_type'); // monthly recurring charge, own DIDs only
        });
    }

    public function down(): void
    {
        Schema::table('dids', fn (Blueprint $t) => $t->dropColumn(['did_type', 'mrc']));
    }
};
