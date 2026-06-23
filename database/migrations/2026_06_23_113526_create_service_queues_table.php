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
        Schema::create('service_queues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained();
            $table->foreignId('doctor_id')->nullable()->constrained();
            $table->foreignId('shift_id')->constrained();
            $table->date('date');
            $table->string('reset_type');
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->string('status');
            $table->unsignedInteger('last_token_number')->default(0);
            $table->timestamps();

            $table->index(['service_id', 'doctor_id', 'status']);
            $table->index(['service_id', 'doctor_id', 'shift_id', 'status']);
            $table->index(['service_id', 'doctor_id', 'date', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('service_queues');
    }
};
