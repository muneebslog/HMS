<?php

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
        Schema::table('lab_invoices', function (Blueprint $table) {
            $table->string('lab_results_status')->default('unknown')->after('status')->index();
            $table->timestamp('lab_results_synced_at')->nullable()->after('lab_results_status');
        });

        Schema::table('lab_invoice_items', function (Blueprint $table) {
            $table->string('outgoing_status')->nullable()->after('is_in_house')->index();
            $table->timestamp('asked_at')->nullable()->after('outgoing_status');
            $table->foreignId('asked_by')->nullable()->after('asked_at')->constrained('users')->nullOnDelete();
            $table->timestamp('given_at')->nullable()->after('asked_by');
            $table->foreignId('given_by')->nullable()->after('given_at')->constrained('users')->nullOnDelete();
            $table->timestamp('received_at')->nullable()->after('given_by');
            $table->foreignId('received_by')->nullable()->after('received_at')->constrained('users')->nullOnDelete();
            $table->string('report_path')->nullable()->after('received_by');
            $table->string('report_original_name')->nullable()->after('report_path');
            $table->timestamp('report_uploaded_at')->nullable()->after('report_original_name');
            $table->foreignId('report_uploaded_by')->nullable()->after('report_uploaded_at')->constrained('users')->nullOnDelete();
            $table->boolean('lab_result_ready')->nullable()->after('report_uploaded_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('lab_invoice_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('asked_by');
            $table->dropConstrainedForeignId('given_by');
            $table->dropConstrainedForeignId('received_by');
            $table->dropConstrainedForeignId('report_uploaded_by');
            $table->dropColumn([
                'outgoing_status',
                'asked_at',
                'given_at',
                'received_at',
                'report_path',
                'report_original_name',
                'report_uploaded_at',
                'lab_result_ready',
            ]);
        });

        Schema::table('lab_invoices', function (Blueprint $table) {
            $table->dropColumn(['lab_results_status', 'lab_results_synced_at']);
        });
    }
};
