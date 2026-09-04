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
        Schema::table('procedure_payments', function (Blueprint $table) {
            $table->timestamp('returned_at')->nullable()->after('discarded_by');
            $table->foreignId('return_requested_by')->nullable()->after('returned_at')->constrained('users')->nullOnDelete();
            $table->string('return_approval_status')->nullable()->after('return_requested_by');
            $table->foreignId('return_reviewed_by')->nullable()->after('return_approval_status')->constrained('users')->nullOnDelete();
            $table->timestamp('return_reviewed_at')->nullable()->after('return_reviewed_by');
            $table->string('return_note')->nullable()->after('return_reviewed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('procedure_payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('return_requested_by');
            $table->dropConstrainedForeignId('return_reviewed_by');
            $table->dropColumn([
                'returned_at',
                'return_approval_status',
                'return_reviewed_at',
                'return_note',
            ]);
        });
    }
};
