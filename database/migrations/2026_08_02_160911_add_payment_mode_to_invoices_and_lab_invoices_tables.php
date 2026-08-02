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
            $table->string('payment_mode')->default('cash')->after('status');
        });

        Schema::table('lab_invoices', function (Blueprint $table) {
            $table->string('payment_mode')->default('cash')->after('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('payment_mode');
        });

        Schema::table('lab_invoices', function (Blueprint $table) {
            $table->dropColumn('payment_mode');
        });
    }
};
