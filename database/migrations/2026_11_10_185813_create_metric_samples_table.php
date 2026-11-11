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
        Schema::create('metric_samples', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->restrictOnDelete();
            $table->foreignId('metric_batch_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sample_index');
            $table->dateTime('collected_at', 6);
            $table->decimal('cpu_percent', 5, 2);
            $table->unsignedBigInteger('memory_total_bytes');
            $table->unsignedBigInteger('memory_used_bytes');
            $table->timestamps();
            $table->unique(['metric_batch_id', 'sample_index']);
            $table->index(['server_id', 'collected_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('metric_samples');
    }
};
