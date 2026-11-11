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
        Schema::create('alert_incidents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('alert_rule_id')->constrained()->cascadeOnDelete();
            $table->foreignId('server_id')->constrained()->restrictOnDelete();
            $table->string('status', 16)->default('open');
            $table->decimal('observed_value', 10, 2)->nullable();
            $table->dateTime('triggered_at');
            $table->dateTime('resolved_at')->nullable();
            $table->dateTime('last_notified_at')->nullable();
            $table->timestamps();
            $table->index(['server_id', 'status']);
            $table->index(['alert_rule_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('alert_incidents');
    }
};
