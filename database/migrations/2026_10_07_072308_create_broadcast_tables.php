<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $t) {
            $t->id();
            $t->string('name', 50)->unique();
            $t->string('label', 100);
            $t->timestamps();
        });

        Schema::create('permissions', function (Blueprint $t) {
            $t->id();
            $t->string('name', 100)->unique();
            $t->timestamps();
        });

        Schema::create('role_user', function (Blueprint $t) {
            $t->foreignId('role_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->primary(['role_id', 'user_id']);
        });

        Schema::create('permission_role', function (Blueprint $t) {
            $t->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $t->foreignId('role_id')->constrained()->cascadeOnDelete();
            $t->primary(['permission_id', 'role_id']);
        });

        Schema::create('dids', function (Blueprint $t) {
            $t->id();
            $t->string('number', 32)->unique();
            $t->string('label', 100)->nullable();
            $t->string('status', 20)->default('active')->index();
            $t->unsignedInteger('max_concurrent_calls')->default(1);
            $t->string('trunk', 100)->nullable();
            $t->timestamps();
        });

        Schema::create('did_user', function (Blueprint $t) {
            $t->foreignId('did_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->timestamps();
            $t->primary(['did_id', 'user_id']);
            $t->index('user_id');
        });

        Schema::create('audio_files', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('original_name');
            $t->string('path');
            $t->string('normalized_path')->nullable();
            $t->string('mime', 100);
            $t->unsignedBigInteger('size');
            $t->decimal('duration', 10, 2)->nullable();
            $t->string('status', 20)->default('UPLOADED')->index();
            $t->string('error')->nullable();
            $t->timestamps();
        });

        Schema::create('campaigns', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('did_id')->constrained()->restrictOnDelete();
            $t->foreignId('audio_file_id')->nullable()->constrained('audio_files')->nullOnDelete();
            $t->string('name');
            $t->string('status', 20)->default('DRAFT');
            $t->unsignedTinyInteger('max_attempts')->default(1); // total attempts, not "retries"
            $t->unsignedInteger('requested_concurrency')->default(1);
            $t->unsignedInteger('retry_delay_seconds')->default(300);
            $t->timestamp('scheduled_at')->nullable();
            $t->timestamp('submitted_at')->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->string('rejection_reason')->nullable();
            $t->timestamps();
            $t->index(['user_id', 'status']);
            $t->index(['did_id', 'status']);
            $t->index('status');
        });

        Schema::create('number_imports', function (Blueprint $t) {
            $t->id();
            $t->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('path');
            $t->string('status', 20)->default('PENDING');
            $t->unsignedInteger('total_rows')->default(0);
            $t->unsignedInteger('valid_rows')->default(0);
            $t->unsignedInteger('invalid_rows')->default(0);
            $t->unsignedInteger('duplicate_rows')->default(0);
            $t->unsignedInteger('imported_rows')->default(0);
            $t->string('error')->nullable();
            $t->timestamps();
        });

        Schema::create('campaign_recipients', function (Blueprint $t) {
            $t->id();
            $t->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $t->string('phone', 32);
            $t->string('status', 20)->default('PENDING'); // PENDING|IN_PROGRESS|RETRY_PENDING|ANSWERED|EXHAUSTED|FAILED|CANCELLED
            $t->unsignedTinyInteger('attempts_count')->default(0);
            $t->timestamp('next_attempt_at')->nullable();
            $t->string('final_result', 30)->nullable();
            $t->timestamps();
            $t->unique(['campaign_id', 'phone']);
            $t->index(['campaign_id', 'status', 'next_attempt_at'], 'recipients_dispatch_idx');
        });

        Schema::create('call_attempts', function (Blueprint $t) {
            $t->id();
            $t->uuid('call_ref')->unique();
            $t->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $t->foreignId('recipient_id')->constrained('campaign_recipients')->cascadeOnDelete();
            $t->foreignId('did_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('phone', 32)->index();
            $t->unsignedTinyInteger('attempt_no');
            $t->string('status', 30)->default('QUEUED');
            $t->unsignedTinyInteger('status_rank')->default(0);
            $t->string('asterisk_uniqueid', 64)->nullable()->index();
            $t->string('asterisk_linkedid', 64)->nullable()->index();
            $t->string('channel')->nullable();
            $t->string('hangup_cause', 20)->nullable();
            $t->string('dial_status', 30)->nullable();
            $t->timestamp('dialed_at')->nullable();
            $t->timestamp('answered_at')->nullable();
            $t->timestamp('ended_at')->nullable();
            $t->unsignedInteger('duration')->default(0);
            $t->unsignedInteger('billsec')->default(0);
            $t->boolean('finalized')->default(false);
            $t->timestamps();
            $t->unique(['recipient_id', 'attempt_no']);
            $t->index(['campaign_id', 'status']);
            $t->index(['did_id', 'status']);
            $t->index(['user_id', 'created_at']);
        });

        // DB-authoritative DID capacity slots: one row == one in-flight call.
        Schema::create('did_slots', function (Blueprint $t) {
            $t->id();
            $t->foreignId('did_id')->constrained()->cascadeOnDelete();
            $t->foreignId('call_attempt_id')->unique()->constrained('call_attempts')->cascadeOnDelete();
            $t->dateTime('acquired_at');
            $t->dateTime('expires_at')->index();
            $t->index('did_id');
        });

        Schema::create('processed_events', function (Blueprint $t) {
            $t->id();
            $t->string('event_key', 191)->unique();
            $t->timestamp('created_at')->useCurrent();
        });

        Schema::create('campaign_approvals', function (Blueprint $t) {
            $t->id();
            $t->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $t->foreignId('admin_id')->constrained('users')->cascadeOnDelete();
            $t->string('decision', 20);
            $t->string('reason')->nullable();
            $t->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('action', 100)->index();
            $t->string('entity', 100);
            $t->unsignedBigInteger('entity_id')->nullable();
            $t->json('old_values')->nullable();
            $t->json('new_values')->nullable();
            $t->string('ip', 45)->nullable();
            $t->string('user_agent')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['entity', 'entity_id']);
        });
    }

    public function down(): void
    {
        foreach (['audit_logs', 'campaign_approvals', 'processed_events', 'did_slots', 'call_attempts', 'campaign_recipients', 'number_imports', 'campaigns', 'audio_files', 'did_user', 'dids', 'permission_role', 'role_user', 'permissions', 'roles'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};

