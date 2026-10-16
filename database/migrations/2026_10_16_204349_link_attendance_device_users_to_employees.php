<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Machine users now link to an HR profile (any staff category) instead of a
     * health aide. Existing links are carried over through the aide profile.
     *
     * Index names differ between environments (they were renamed after first
     * deploy), so they are found by column. Dropping the old foreign key makes
     * SQLite rebuild the table, which nulls `attendance_punches` links, so those
     * are saved first and written back afterwards.
     */
    public function up(): void
    {
        Schema::table('attendance_device_users', function (Blueprint $table) {
            $table->unsignedBigInteger('employee_id')->nullable()->after('card_number');
        });

        foreach (DB::table('attendance_device_users')->whereNotNull('health_aide_id')->get(['id', 'health_aide_id']) as $deviceUser) {
            DB::table('attendance_device_users')->where('id', $deviceUser->id)->update([
                'employee_id' => DB::table('health_aides')->where('id', $deviceUser->health_aide_id)->value('employee_id'),
            ]);
        }

        $punchLinks = DB::table('attendance_punches')
            ->whereNotNull('attendance_device_user_id')
            ->pluck('attendance_device_user_id', 'id');

        $hasAideForeignKey = collect(Schema::getForeignKeys('attendance_device_users'))
            ->contains(fn (array $foreignKey): bool => $foreignKey['columns'] === ['health_aide_id']);

        $aideIndexes = collect(Schema::getIndexes('attendance_device_users'))
            ->filter(fn (array $index): bool => in_array('health_aide_id', $index['columns'], true) && ! $index['primary'])
            ->pluck('name');

        Schema::table('attendance_device_users', function (Blueprint $table) use ($hasAideForeignKey, $aideIndexes) {
            if ($hasAideForeignKey) {
                $table->dropForeign(['health_aide_id']);
            }

            $aideIndexes->each(fn (string $name) => $table->dropIndex($name));
        });

        Schema::table('attendance_device_users', function (Blueprint $table) {
            $table->dropColumn('health_aide_id');
        });

        Schema::table('attendance_device_users', function (Blueprint $table) {
            $table->unique(['attendance_device_id', 'employee_id'], 'attendance_device_users_device_employee_unique');
        });

        $this->restorePunchLinks($punchLinks);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attendance_device_users', function (Blueprint $table) {
            $table->dropUnique('attendance_device_users_device_employee_unique');
            $table->unsignedBigInteger('health_aide_id')->nullable()->after('card_number');
            $table->unique(['attendance_device_id', 'health_aide_id'], 'attendance_device_users_device_aide_unique');
        });

        foreach (DB::table('attendance_device_users')->whereNotNull('employee_id')->get(['id', 'employee_id']) as $deviceUser) {
            DB::table('attendance_device_users')->where('id', $deviceUser->id)->update([
                'health_aide_id' => DB::table('health_aides')->where('employee_id', $deviceUser->employee_id)->value('id'),
            ]);
        }

        Schema::table('attendance_device_users', function (Blueprint $table) {
            $table->dropColumn('employee_id');
        });
    }

    /**
     * Write back punch → machine user links that a table rebuild may have cleared.
     *
     * @param  Collection<int, int>  $punchLinks
     */
    private function restorePunchLinks($punchLinks): void
    {
        $punchLinks
            ->groupBy(fn (int $deviceUserId): int => $deviceUserId, preserveKeys: true)
            ->each(function ($group, int $deviceUserId): void {
                foreach (array_chunk($group->keys()->all(), 500) as $punchIds) {
                    DB::table('attendance_punches')
                        ->whereIn('id', $punchIds)
                        ->whereNull('attendance_device_user_id')
                        ->update(['attendance_device_user_id' => $deviceUserId]);
                }
            });
    }
};
