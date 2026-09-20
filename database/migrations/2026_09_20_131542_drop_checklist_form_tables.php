<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::dropIfExists('nurse_questionnaire_responses');
        Schema::dropIfExists('nurse_questionnaire_entries');
        Schema::dropIfExists('nurse_questionnaire_questions');
        Schema::dropIfExists('nurse_questionnaires');

        Schema::dropIfExists('ward_maintenance_faults');
        Schema::dropIfExists('ward_maintenance_answers');
        Schema::dropIfExists('ward_maintenance_entries');

        Schema::dropIfExists('equipment_inspection_register_rows');
        Schema::dropIfExists('equipment_inspection_answers');
        Schema::dropIfExists('equipment_inspection_entries');

        Schema::dropIfExists('emergency_department_log_answers');
        Schema::dropIfExists('emergency_department_log_entries');

        if (Schema::hasTable('role_page_permissions')) {
            DB::table('role_page_permissions')
                ->where(function ($query): void {
                    $query->where('route_name', 'like', 'incharge.questionnaires%')
                        ->orWhere('route_name', 'like', 'incharge.questionnaire%')
                        ->orWhere('route_name', 'like', 'incharge.ward-maintenance%')
                        ->orWhere('route_name', 'like', 'incharge.equipment-inspection%')
                        ->orWhere('route_name', 'like', 'incharge.emergency-department-log%')
                        ->orWhere('route_name', 'like', 'admin.nurse-questionnaire%')
                        ->orWhere('route_name', 'like', 'admin.ward-maintenance%')
                        ->orWhere('route_name', 'like', 'admin.equipment-inspection%')
                        ->orWhere('route_name', 'like', 'admin.emergency-department-log%');
                })
                ->delete();
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Checklist form features were removed; recreate via earlier migrations if needed.
    }
};
