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
            $table->foreignId('referred_by_doctor_id')->nullable()->after('shift_id')->constrained('doctors')->nullOnDelete();
            $table->decimal('doctor_share', 5, 2)->nullable()->after('referred_by_doctor_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('lab_invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('referred_by_doctor_id');
            $table->dropColumn('doctor_share');
        });
    }
};
