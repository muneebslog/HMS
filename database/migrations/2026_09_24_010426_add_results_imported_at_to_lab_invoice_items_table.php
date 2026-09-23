<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Marks tests whose results were imported from the old lab software, so
     * they can be labelled on screen and the import can be rolled back exactly.
     */
    public function up(): void
    {
        Schema::table('lab_invoice_items', function (Blueprint $table) {
            $table->timestamp('results_imported_at')->nullable()->after('results_completed_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('lab_invoice_items', function (Blueprint $table) {
            $table->dropColumn('results_imported_at');
        });
    }
};
