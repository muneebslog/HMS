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
        Schema::table('medication_order_injections', function (Blueprint $table) {
            $table->string('administration_type')->after('injection_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('medication_order_injections', function (Blueprint $table) {
            $table->dropColumn('administration_type');
        });
    }
};
