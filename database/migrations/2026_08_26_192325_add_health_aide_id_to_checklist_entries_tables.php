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
        Schema::table('ward_maintenance_entries', function (Blueprint $table) {
            $table->foreignId('health_aide_id')
                ->nullable()
                ->after('user_id')
                ->constrained('health_aides')
                ->nullOnDelete();
        });

        Schema::table('equipment_inspection_entries', function (Blueprint $table) {
            $table->foreignId('health_aide_id')
                ->nullable()
                ->after('user_id')
                ->constrained('health_aides')
                ->nullOnDelete();
        });

        Schema::table('emergency_department_log_entries', function (Blueprint $table) {
            $table->foreignId('health_aide_id')
                ->nullable()
                ->after('user_id')
                ->constrained('health_aides')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ward_maintenance_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('health_aide_id');
        });

        Schema::table('equipment_inspection_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('health_aide_id');
        });

        Schema::table('emergency_department_log_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('health_aide_id');
        });
    }
};
