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
        Schema::table('procedure_apparent_invoices', function (Blueprint $table) {
            $table->date('admission_date')->nullable()->after('total');
            $table->date('discharge_date')->nullable()->after('admission_date');
            $table->date('issued_date')->nullable()->after('discharge_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('procedure_apparent_invoices', function (Blueprint $table) {
            $table->dropColumn(['admission_date', 'discharge_date', 'issued_date']);
        });
    }
};
