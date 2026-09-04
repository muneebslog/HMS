<?php

use App\Enums\ProcedureStatus;
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
        Schema::table('procedures', function (Blueprint $table) {
            $table->string('status')
                ->default(ProcedureStatus::Booking->value)
                ->after('shift_id')
                ->index();
        });

        DB::table('procedures')
            ->whereNotNull('discharged_at')
            ->update(['status' => ProcedureStatus::Discharged->value]);

        DB::table('procedures')
            ->whereNotNull('admitted_at')
            ->whereNull('discharged_at')
            ->update(['status' => ProcedureStatus::Admitted->value]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('procedures', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropColumn('status');
        });
    }
};
