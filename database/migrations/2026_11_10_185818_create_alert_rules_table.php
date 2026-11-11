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
        Schema::create('alert_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('server_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('metric', 32);
            $table->string('operator', 8);
            $table->decimal('threshold', 10, 2);
            $table->unsignedSmallInteger('duration_seconds')->default(0);
            $table->unsignedSmallInteger('cooldown_seconds')->default(300);
            $table->boolean('enabled')->default(true);
            $table->timestamps();
            $table->index(['user_id', 'enabled']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('alert_rules');
    }
};
