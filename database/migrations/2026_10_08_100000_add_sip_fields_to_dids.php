<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dids', function (Blueprint $t) {
            $t->string('sip_host', 190)->nullable()->after('trunk');
            $t->unsignedSmallInteger('sip_port')->default(5060)->after('sip_host');
            $t->string('sip_username', 64)->nullable()->after('sip_port');
            $t->text('sip_password')->nullable()->after('sip_username'); // encrypted by the model cast
            $t->string('sip_status', 20)->nullable()->after('sip_password'); // registered|rejected|unregistered|pending|unreachable|inactive
            $t->string('sip_status_detail', 255)->nullable()->after('sip_status');
            $t->timestamp('sip_checked_at')->nullable()->after('sip_status_detail');
        });
    }

    public function down(): void
    {
        Schema::table('dids', fn (Blueprint $t) => $t->dropColumn(['sip_host', 'sip_port', 'sip_username', 'sip_password', 'sip_status', 'sip_status_detail', 'sip_checked_at']));
    }
};
