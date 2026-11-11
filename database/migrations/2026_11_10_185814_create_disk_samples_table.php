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
        Schema::create('disk_samples', function (Blueprint $table) {
            $table->id();
            $table->foreignId('metric_sample_id')->constrained()->cascadeOnDelete();
            $table->string('filesystem', 255);
            $table->unsignedBigInteger('total_bytes');
            $table->unsignedBigInteger('used_bytes');
            $table->timestamps();
            $table->unique(['metric_sample_id', 'filesystem']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('disk_samples');
    }
};
