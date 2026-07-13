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
        Schema::create('patient_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('queue_token_id')->constrained('queue_tokens');
            $table->foreignId('called_by')->constrained('users');
            $table->timestamp('called_at');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['queue_token_id']);
            $table->index(['called_by']);
            $table->index(['called_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('patient_calls');
    }
};
