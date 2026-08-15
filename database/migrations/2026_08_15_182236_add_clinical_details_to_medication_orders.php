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
        Schema::table('medication_orders', function (Blueprint $table) {
            $table->text('complaint_or_diagnosis')->nullable()->after('status');
        });

        Schema::table('medication_order_injections', function (Blueprint $table) {
            $table->string('comment')->nullable()->after('administration_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('medication_order_injections', function (Blueprint $table) {
            $table->dropColumn('comment');
        });

        Schema::table('medication_orders', function (Blueprint $table) {
            $table->dropColumn('complaint_or_diagnosis');
        });
    }
};
