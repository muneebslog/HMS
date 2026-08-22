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
        Schema::table('health_aides', function (Blueprint $table) {
            $table->string('device_user_id')->nullable()->unique()->after('is_active');
            $table->timestamp('attendance_enrolled_at')->nullable()->after('device_user_id');
        });

        Schema::create('attendance_devices', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('ip_address');
            $table->unsignedSmallInteger('port')->default(4370);
            $table->string('serial_number')->nullable();
            $table->json('settings')->nullable();
            $table->timestamp('last_sync_at')->nullable();
            $table->string('last_sync_status')->nullable();
            $table->text('last_sync_error')->nullable();
            $table->unsignedSmallInteger('consecutive_sync_failures')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('duty_shift_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->time('start_time');
            $table->time('end_time');
            $table->unsignedSmallInteger('grace_minutes_in')->default(15);
            $table->unsignedSmallInteger('grace_minutes_out')->default(10);
            $table->unsignedSmallInteger('break_minutes')->default(0);
            $table->string('station')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('health_aide_leaves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('health_aide_id')->constrained()->cascadeOnDelete();
            $table->date('leave_date');
            $table->foreignId('replacement_health_aide_id')->nullable()->constrained('health_aides')->nullOnDelete();
            $table->time('duty_start_time')->nullable();
            $table->time('duty_end_time')->nullable();
            $table->boolean('is_informed')->default(false);
            $table->string('informed_by')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->unique(['health_aide_id', 'leave_date']);
        });

        Schema::create('duty_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('health_aide_id')->constrained()->cascadeOnDelete();
            $table->foreignId('duty_shift_template_id')->nullable()->constrained()->nullOnDelete();
            $table->date('date');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('assignment_type')->default('regular');
            $table->foreignId('replaces_health_aide_id')->nullable()->constrained('health_aides')->nullOnDelete();
            $table->foreignId('health_aide_leave_id')->nullable()->constrained('health_aide_leaves')->nullOnDelete();
            $table->string('station')->nullable();
            $table->text('notes')->nullable();
            $table->string('status')->default('scheduled');
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['date', 'status']);
            $table->index(['health_aide_id', 'starts_at']);
        });

        Schema::create('attendance_punches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attendance_device_id')->constrained()->cascadeOnDelete();
            $table->string('device_punch_uid');
            $table->string('device_user_id');
            $table->foreignId('health_aide_id')->nullable()->constrained()->nullOnDelete();
            $table->dateTime('punched_at');
            $table->unsignedTinyInteger('verify_type')->nullable();
            $table->unsignedSmallInteger('punch_state')->nullable();
            $table->string('punch_state_source')->default('device');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['attendance_device_id', 'device_punch_uid']);
            $table->index(['health_aide_id', 'punched_at']);
        });

        Schema::create('attendance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('health_aide_id')->constrained()->cascadeOnDelete();
            $table->foreignId('duty_assignment_id')->nullable()->constrained()->nullOnDelete();
            $table->date('date');
            $table->dateTime('scheduled_starts_at')->nullable();
            $table->dateTime('scheduled_ends_at')->nullable();
            $table->dateTime('first_in_at')->nullable();
            $table->dateTime('last_out_at')->nullable();
            $table->unsignedInteger('worked_minutes')->default(0);
            $table->unsignedInteger('late_minutes')->default(0);
            $table->unsignedInteger('early_leave_minutes')->default(0);
            $table->unsignedInteger('overtime_minutes')->default(0);
            $table->unsignedInteger('payable_minutes')->default(0);
            $table->string('status');
            $table->boolean('is_manual_override')->default(false);
            $table->text('override_reason')->nullable();
            $table->foreignId('overridden_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['date', 'status']);
            $table->unique(['health_aide_id', 'duty_assignment_id']);
        });

        Schema::create('attendance_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attendance_record_id')->constrained()->cascadeOnDelete();
            $table->string('field_changed');
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->text('reason');
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendance_adjustments');
        Schema::dropIfExists('attendance_records');
        Schema::dropIfExists('attendance_punches');
        Schema::dropIfExists('duty_assignments');
        Schema::dropIfExists('health_aide_leaves');
        Schema::dropIfExists('duty_shift_templates');
        Schema::dropIfExists('attendance_devices');

        Schema::table('health_aides', function (Blueprint $table) {
            $table->dropColumn(['device_user_id', 'attendance_enrolled_at']);
        });
    }
};
