<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('servers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->uuid('uuid')->unique();
            $table->string('name', 120);
            $table->string('platform', 16);
            $table->string('hostname')->nullable();
            $table->string('agent_version', 64)->nullable();
            $table->string('status', 16)->default('pending');
            $table->dateTime('last_seen_at')->nullable();
            $table->dateTime('last_sample_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('servers');
    }
};
