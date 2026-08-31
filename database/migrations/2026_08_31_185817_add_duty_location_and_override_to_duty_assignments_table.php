<?php

use App\Models\DutyAssignment;
use App\Models\DutyLocation;
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
        Schema::table('duty_assignments', function (Blueprint $table) {
            $table->foreignId('duty_location_id')->nullable()->after('health_aide_leave_id')->constrained()->nullOnDelete();
            $table->boolean('is_override')->default(false)->after('duty_location_id');
        });

        $stations = DutyAssignment::query()
            ->whereNotNull('station')
            ->where('station', '!=', '')
            ->distinct()
            ->pluck('station');

        foreach ($stations as $station) {
            DutyLocation::query()->firstOrCreate(
                ['name' => $station],
                ['sort_order' => 0, 'is_active' => true],
            );
        }

        DutyAssignment::query()
            ->whereNotNull('station')
            ->where('station', '!=', '')
            ->each(function (DutyAssignment $assignment): void {
                $location = DutyLocation::query()->where('name', $assignment->station)->first();

                if ($location !== null) {
                    $assignment->update(['duty_location_id' => $location->id]);
                }
            });
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
