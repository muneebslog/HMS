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
        Schema::create('attendance_device_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attendance_device_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('device_uid')->nullable();
            $table->string('device_user_id');
            $table->string('name')->nullable();
            $table->foreignId('health_aide_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['attendance_device_id', 'device_user_id'], 'att_device_users_device_user_unique');
            $table->index('health_aide_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendance_device_users');
    }
};
