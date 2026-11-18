<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider_id')->nullable()->unique();
            $table->string('idempotency_key')->unique();
            $table->string('status')->default('queued');
            $table->text('prompt');
            $table->string('size');
            $table->string('quality');
            $table->json('reference_images');
            $table->bigInteger('estimated_provider_cost_nano');
            $table->bigInteger('estimated_charge_nano');
            $table->bigInteger('reserved_nano')->nullable();
            $table->bigInteger('actual_provider_cost_nano')->nullable();
            $table->bigInteger('actual_charge_nano')->nullable();
            $table->boolean('settled')->default(false);
            $table->text('image_url')->nullable();
            $table->timestamp('image_url_expires_at')->nullable();
            $table->json('provider_usage')->nullable();
            $table->json('rates')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('provider_created_at')->nullable();
            $table->timestamp('provider_started_at')->nullable();
            $table->timestamp('provider_completed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'provider_completed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generations');
    }
};
