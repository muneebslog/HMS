<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * HMS-owned marker for when an in-house test's results were completed in
     * the HMS. Independent of `lab_result_ready`, which the old lab software sync writes.
     */
    public function up(): void
    {
        Schema::table('lab_invoice_items', function (Blueprint $table) {
            $table->timestamp('results_completed_at')->nullable()->after('lab_result_ready');
            $table->foreignId('results_completed_by')->nullable()->after('results_completed_at')->constrained('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('lab_invoice_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('results_completed_by');
            $table->dropColumn('results_completed_at');
        });
    }
};
