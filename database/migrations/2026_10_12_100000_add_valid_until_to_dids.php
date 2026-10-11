<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Last day the balance (reseller DID) / MRC (own DID) is valid; required in the form (older DIDs may have none = no expiry). Calls stop after it.
        Schema::table('dids', fn (Blueprint $t) => $t->date('valid_until')->nullable()->after('mrc'));
    }

    public function down(): void
    {
        Schema::table('dids', fn (Blueprint $t) => $t->dropColumn('valid_until'));
    }
};
