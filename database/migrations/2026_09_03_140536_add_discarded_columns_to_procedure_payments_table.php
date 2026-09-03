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
            $table->timestamp('discarded_at')->nullable()->index()->after('shift_id');
            $table->foreignId('discarded_by')->nullable()->after('discarded_at')->constrained('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('procedure_payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('discarded_by');
            $table->dropColumn('discarded_at');
        });
    }
};
