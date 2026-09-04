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
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('return_approval_status')->nullable()->after('status');
            $table->foreignId('return_requested_by')->nullable()->after('return_approval_status')->constrained('users')->nullOnDelete();
            $table->foreignId('return_reviewed_by')->nullable()->after('return_requested_by')->constrained('users')->nullOnDelete();
            $table->timestamp('return_reviewed_at')->nullable()->after('return_reviewed_by');
            $table->string('return_note')->nullable()->after('return_reviewed_at');
        });

        Schema::table('lab_invoices', function (Blueprint $table) {
            $table->string('return_approval_status')->nullable()->after('status');
            $table->foreignId('return_requested_by')->nullable()->after('return_approval_status')->constrained('users')->nullOnDelete();
            $table->foreignId('return_reviewed_by')->nullable()->after('return_requested_by')->constrained('users')->nullOnDelete();
            $table->timestamp('return_reviewed_at')->nullable()->after('return_reviewed_by');
            $table->string('return_note')->nullable()->after('return_reviewed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('return_requested_by');
            $table->dropConstrainedForeignId('return_reviewed_by');
            $table->dropColumn([
                'return_approval_status',
                'return_reviewed_at',
                'return_note',
            ]);
        });

        Schema::table('lab_invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('return_requested_by');
            $table->dropConstrainedForeignId('return_reviewed_by');
            $table->dropColumn([
                'return_approval_status',
                'return_reviewed_at',
                'return_note',
            ]);
        });
    }
};
