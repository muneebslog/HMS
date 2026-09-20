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
        Schema::table('duty_assignments', function (Blueprint $table) {
            $table->foreignId('duty_location_id')->nullable()->after('health_aide_leave_id')->constrained()->nullOnDelete();
            $table->boolean('is_override')->default(false)->after('duty_location_id');
        });

        $stations = DB::table('duty_assignments')
            ->whereNotNull('station')
            ->where('station', '!=', '')
            ->distinct()
            ->pluck('station');

        foreach ($stations as $station) {
            $locationId = DB::table('duty_locations')->where('name', $station)->value('id');

            if ($locationId === null) {
                $locationId = DB::table('duty_locations')->insertGetId([
                    'name' => $station,
                    'sort_order' => 0,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('duty_assignments')
                ->where('station', $station)
                ->update(['duty_location_id' => $locationId]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('duty_assignments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('duty_location_id');
            $table->dropColumn('is_override');
        });
    }
};
