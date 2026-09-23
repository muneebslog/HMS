<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Remove the "Procedure Readings Missing" admin notifications. The indoor pages that
     * recorded hourly vitals / fetal heart readings were removed, so every one of these
     * was a false alarm, and the hourly check that created them has been removed too.
     */
    public function up(): void
    {
        DB::table('admin_notifications')->where('type', 'procedure_vitals_missing')->delete();
    }

    /**
     * The deleted false alarms are not restored.
     */
    public function down(): void
    {
        //
    }
};
