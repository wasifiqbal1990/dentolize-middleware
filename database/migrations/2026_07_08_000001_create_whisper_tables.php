<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inboxes', function (Blueprint $table): void {
            $table->id();
            $table->string('dentolize_event_id')->unique();
            $table->string('event_type');
            $table->json('raw_payload');
            $table->json('headers')->nullable();
            $table->string('processing_status')->default('received');
            $table->timestampTz('received_at');
            $table->timestampTz('processed_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('sync_maps', function (Blueprint $table): void {
            $table->id();
            $table->string('entity_type');
            $table->string('dentolize_id');
            $table->string('dentolize_number')->nullable();
            $table->string('qoyod_id')->nullable();
            $table->string('qoyod_reference')->nullable();
            $table->decimal('amount', 14, 2)->nullable();
            $table->string('status')->default('pending');
            $table->string('rejected_by')->nullable();
            $table->text('last_error')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->string('payload_hash')->nullable();
            $table->timestampTz('first_seen_at')->nullable();
            $table->timestampTz('last_attempt_at')->nullable();
            $table->timestampTz('synced_at')->nullable();
            $table->timestampsTz();
            $table->unique(['entity_type', 'dentolize_id']);
            $table->index(['status', 'entity_type']);
        });

        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('correlation_id');
            $table->foreignId('sync_map_id')->nullable()->constrained('sync_maps')->nullOnDelete();
            $table->string('action');
            $table->string('target_system');
            $table->string('endpoint')->nullable();
            $table->string('http_method')->nullable();
            $table->json('request_body')->nullable();
            $table->json('response_body')->nullable();
            $table->unsignedSmallInteger('response_code')->nullable();
            $table->timestampsTz();
        });

        Schema::create('dentolize_mirrors', function (Blueprint $table): void {
            $table->id();
            $table->string('entity_type');
            $table->string('dentolize_id');
            $table->string('dentolize_number')->nullable();
            $table->json('raw');
            $table->timestampTz('source_created_at')->nullable();
            $table->timestampTz('source_updated_at')->nullable();
            $table->string('payload_hash');
            $table->boolean('synced_to_qoyod')->default(false);
            $table->timestampTz('pulled_at');
            $table->timestampsTz();
            $table->unique(['entity_type', 'dentolize_id']);
        });

        Schema::create('sync_checkpoints', function (Blueprint $table): void {
            $table->string('entity_type')->primary();
            $table->timestampTz('last_updated_at_seen')->nullable();
            $table->timestampTz('last_full_sweep_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('admin_users', function (Blueprint $table): void {
            $table->id();
            $table->string('email')->unique();
            $table->string('password_hash');
            $table->string('role')->default('viewer');
            $table->timestampTz('last_login_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('admin_access_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('admin_user_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->string('action');
            $table->string('item_ref')->nullable();
            $table->string('ip')->nullable();
            $table->timestampsTz();
        });

        Schema::create('fake_qoyod_records', function (Blueprint $table): void {
            $table->id();
            $table->string('record_type');
            $table->string('reference');
            $table->json('payload');
            $table->decimal('amount', 14, 2)->nullable();
            $table->timestampsTz();
            $table->unique(['record_type', 'reference']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fake_qoyod_records');
        Schema::dropIfExists('admin_access_logs');
        Schema::dropIfExists('admin_users');
        Schema::dropIfExists('sync_checkpoints');
        Schema::dropIfExists('dentolize_mirrors');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('sync_maps');
        Schema::dropIfExists('inboxes');
    }
};
