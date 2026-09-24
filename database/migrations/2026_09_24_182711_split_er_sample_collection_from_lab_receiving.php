<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The ER Station collects samples; the lab still receives them. Samples an aide marked
     * at the ER become "collected", and unfinished ones go back to the lab's receiving list.
     */
    public function up(): void
    {
        Schema::table('lab_invoice_items', function (Blueprint $table) {
            $table->timestamp('sample_collected_at')->nullable()->after('is_in_house');
            $table->foreignId('sample_collected_by_health_aide_id')->nullable()->after('sample_collected_at')->constrained('health_aides')->nullOnDelete();
        });

        DB::table('lab_invoice_items')
            ->whereNotNull('sample_received_by_health_aide_id')
            ->update([
                'sample_collected_at' => DB::raw('sample_received_at'),
                'sample_collected_by_health_aide_id' => DB::raw('sample_received_by_health_aide_id'),
            ]);

        DB::table('lab_invoice_items')
            ->whereNotNull('sample_received_by_health_aide_id')
            ->whereNull('sample_received_by')
            ->whereNull('results_completed_at')
            ->update(['sample_received_at' => null]);

        Schema::table('lab_invoice_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sample_received_by_health_aide_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('lab_invoice_items', function (Blueprint $table) {
            $table->foreignId('sample_received_by_health_aide_id')->nullable()->after('sample_received_by')->constrained('health_aides')->nullOnDelete();
        });

        DB::table('lab_invoice_items')
            ->whereNotNull('sample_collected_by_health_aide_id')
            ->whereNull('sample_received_at')
            ->update([
                'sample_received_at' => DB::raw('sample_collected_at'),
                'sample_received_by_health_aide_id' => DB::raw('sample_collected_by_health_aide_id'),
            ]);

        Schema::table('lab_invoice_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sample_collected_by_health_aide_id');
            $table->dropColumn('sample_collected_at');
        });
    }
};
