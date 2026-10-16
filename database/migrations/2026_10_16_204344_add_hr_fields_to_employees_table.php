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
        Schema::table('employees', function (Blueprint $table) {
            $table->string('staff_category')->default('non_medical')->after('department')->index();
            $table->boolean('tracks_attendance')->default(true)->after('staff_category');
            $table->unsignedTinyInteger('expected_monthly_duties')->nullable()->after('tracks_attendance');
        });

        DB::table('employees')
            ->whereNotNull('doctor_id')
            ->update(['staff_category' => 'doctor', 'tracks_attendance' => false]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropIndex(['staff_category']);
            $table->dropColumn(['staff_category', 'tracks_attendance', 'expected_monthly_duties']);
        });
    }
};
