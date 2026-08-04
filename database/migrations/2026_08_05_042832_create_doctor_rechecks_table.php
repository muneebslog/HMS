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
        Schema::create('doctor_rechecks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('queue_token_id')->constrained()->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('set_by')->constrained('users')->cascadeOnDelete();
            $table->unsignedSmallInteger('minutes');
            $table->string('note')->nullable();
            $table->timestamp('due_at')->index();
            $table->timestamp('notified_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamps();

            $table->index(['queue_token_id', 'acknowledged_at']);
            $table->index(['due_at', 'acknowledged_at', 'notified_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('doctor_rechecks');
    }
};
