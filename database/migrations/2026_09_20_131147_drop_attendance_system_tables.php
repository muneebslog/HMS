<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::dropIfExists('attendance_adjustments');
        Schema::dropIfExists('attendance_records');
        Schema::dropIfExists('attendance_work_sessions');
        Schema::dropIfExists('attendance_punches');
        Schema::dropIfExists('attendance_device_users');
        Schema::dropIfExists('duty_assignments');
        Schema::dropIfExists('health_aide_leaves');
        Schema::dropIfExists('duty_shift_templates');
        Schema::dropIfExists('duty_locations');
        Schema::dropIfExists('attendance_devices');

        if (Schema::hasTable('health_aides') && Schema::hasColumn('health_aides', 'device_user_id')) {
            // SQLite cannot drop a unique-indexed column until the index is gone.
            $indexes = collect(Schema::getIndexes('health_aides'))
                ->pluck('name')
                ->all();

            if (in_array('health_aides_device_user_id_unique', $indexes, true)) {
                Schema::table('health_aides', function (Blueprint $table) {
                    $table->dropUnique('health_aides_device_user_id_unique');
                });
            }

            Schema::table('health_aides', function (Blueprint $table) {
                $columns = ['device_user_id'];

                if (Schema::hasColumn('health_aides', 'attendance_enrolled_at')) {
                    $columns[] = 'attendance_enrolled_at';
                }

                $table->dropColumn($columns);
            });
        }

        if (Schema::hasTable('role_page_permissions')) {
            DB::table('role_page_permissions')
                ->where('route_name', 'like', 'admin.attendance%')
                ->delete();
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Attendance system was removed; recreate via earlier migrations if needed.
    }
};
