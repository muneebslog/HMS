<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reception can switch an invoice between cash and online; record who did it and when.
     */
    public function up(): void
    {
        foreach (['invoices', 'lab_invoices'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->timestamp('payment_mode_changed_at')->nullable()->after('payment_mode');
                $table->foreignId('payment_mode_changed_by')->nullable()->after('payment_mode_changed_at')->constrained('users')->nullOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['invoices', 'lab_invoices'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropConstrainedForeignId('payment_mode_changed_by');
                $table->dropColumn('payment_mode_changed_at');
            });
        }
    }
};
