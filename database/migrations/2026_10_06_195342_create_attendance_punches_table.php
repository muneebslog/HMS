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
        Schema::create('attendance_punches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attendance_device_id')->constrained()->cascadeOnDelete();
            $table->foreignId('attendance_device_user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('device_user_id');
            $table->dateTime('punched_at');
            $table->unsignedTinyInteger('verify_type')->nullable();
            $table->unsignedTinyInteger('punch_state')->nullable();
            $table->timestamps();

            $table->unique(['attendance_device_id', 'device_user_id', 'punched_at'], 'attendance_punches_device_user_time_unique');
            $table->index('punched_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendance_punches');
    }
};
