<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Every health aide gets an HR profile: an unclaimed employee with the exact
     * same name is reused, otherwise a new "health aide" profile is created.
     *
     * The link is a plain indexed column (employees are never hard-deleted): a
     * foreign key would force SQLite to rebuild `health_aides`, and that rebuild
     * nulls every delivery, sample and stock row pointing at an aide.
     */
    public function up(): void
    {
        Schema::table('health_aides', function (Blueprint $table) {
            $table->unsignedBigInteger('employee_id')->nullable()->after('id')->unique();
        });

        $creatorId = DB::table('users')->where('role', 'admin')->orderBy('id')->value('id')
            ?? DB::table('users')->orderBy('id')->value('id');

        if ($creatorId === null) {
            return;
        }

        $now = now();

        foreach (DB::table('health_aides')->orderBy('id')->get(['id', 'name', 'is_active']) as $aide) {
            $matches = DB::table('employees')
                ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower(trim($aide->name))])
                ->whereNotIn('id', DB::table('health_aides')->whereNotNull('employee_id')->select('employee_id'))
                ->pluck('id');

            if ($matches->count() === 1) {
                $employeeId = $matches->first();

                DB::table('employees')->where('id', $employeeId)->update([
                    'staff_category' => 'health_aide',
                    'tracks_attendance' => true,
                ]);
            } else {
                $employeeId = DB::table('employees')->insertGetId([
                    'name' => trim($aide->name),
                    'designation' => 'Health Aide',
                    'staff_category' => 'health_aide',
                    'tracks_attendance' => true,
                    'employment_type' => 'full_time',
                    'status' => $aide->is_active ? 'active' : 'inactive',
                    'created_by' => $creatorId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            DB::table('health_aides')->where('id', $aide->id)->update(['employee_id' => $employeeId]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('health_aides', function (Blueprint $table) {
            $table->dropUnique(['employee_id']);
        });

        Schema::table('health_aides', function (Blueprint $table) {
            $table->dropColumn('employee_id');
        });
    }
};
