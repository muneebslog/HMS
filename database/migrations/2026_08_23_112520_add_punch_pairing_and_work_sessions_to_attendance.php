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
        Schema::table('attendance_punches', function (Blueprint $table) {
            $table->string('pairing_role')->nullable()->after('punch_state_source');
            $table->text('notes')->nullable()->after('pairing_role');
            $table->foreignId('created_by')->nullable()->after('notes')->constrained('users')->nullOnDelete();
        });

        Schema::table('attendance_punches', function (Blueprint $table) {
            $table->dropForeign(['attendance_device_id']);
        });

        Schema::table('attendance_punches', function (Blueprint $table) {
            $table->foreignId('attendance_device_id')->nullable()->change();
            $table->foreign('attendance_device_id')
                ->references('id')
                ->on('attendance_devices')
                ->cascadeOnDelete();
        });

        Schema::table('attendance_records', function (Blueprint $table) {
            $table->foreignId('attendance_work_session_id')
                ->nullable()
                ->after('duty_assignment_id')
                ->constrained('attendance_work_sessions')
                ->nullOnDelete();

            $table->unique('attendance_work_session_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->dropUnique(['attendance_work_session_id']);
            $table->dropConstrainedForeignId('attendance_work_session_id');
        });

        Schema::table('attendance_punches', function (Blueprint $table) {
            $table->dropForeign(['attendance_device_id']);
        });

        Schema::table('attendance_punches', function (Blueprint $table) {
            $table->foreignId('attendance_device_id')->nullable(false)->change();
            $table->foreign('attendance_device_id')
                ->references('id')
                ->on('attendance_devices')
                ->cascadeOnDelete();
        });

        Schema::table('attendance_punches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
            $table->dropColumn(['pairing_role', 'notes']);
        });
    }
};
