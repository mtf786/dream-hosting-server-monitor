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
        Schema::create('metric_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->restrictOnDelete();
            $table->uuid('batch_uuid');
            $table->string('agent_version', 64)->nullable();
            $table->unsignedSmallInteger('sample_count');
            $table->dateTime('received_at');
            $table->timestamps();
            $table->unique(['server_id', 'batch_uuid']);
            $table->index(['server_id', 'received_at']);
            $table->index('received_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('metric_batches');
    }
};
