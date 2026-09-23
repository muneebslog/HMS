<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Track when the lab receives an in-house test's sample from reception.
     * In-house tests that already exist are treated as received when they were billed,
     * so the new "Sample Receiving" queue starts empty instead of listing old cases.
     */
    public function up(): void
    {
        Schema::table('lab_invoice_items', function (Blueprint $table) {
            $table->timestamp('sample_received_at')->nullable()->after('is_in_house')->index();
            $table->foreignId('sample_received_by')->nullable()->after('sample_received_at')->constrained('users')->nullOnDelete();
        });

        DB::table('lab_invoice_items')
            ->where('is_in_house', true)
            ->update(['sample_received_at' => DB::raw('created_at')]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('lab_invoice_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sample_received_by');
            $table->dropIndex(['sample_received_at']);
            $table->dropColumn('sample_received_at');
        });
    }
};
