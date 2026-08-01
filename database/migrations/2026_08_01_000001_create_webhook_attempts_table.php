<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_attempts', function (Blueprint $table): void {
            $table->id();
            $table->string('source')->default('dentolize');
            $table->string('http_method');
            $table->string('path');
            $table->string('content_type')->nullable();
            $table->string('user_agent')->nullable();
            $table->string('event_id')->nullable();
            $table->string('event_type')->nullable();
            $table->boolean('verify_token_present')->default(false);
            $table->boolean('verify_token_valid')->default(false);
            $table->string('result');
            $table->json('payload_keys')->nullable();
            $table->timestampTz('received_at');
            $table->timestampsTz();
            $table->index(['source', 'result']);
            $table->index('received_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_attempts');
    }
};
