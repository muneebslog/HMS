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
            $table->dropForeign(['shift_id']);
        });

        Schema::table('procedure_payments', function (Blueprint $table) {
            $table->foreignId('shift_id')->nullable()->change();
            $table->foreign('shift_id')->references('id')->on('shifts')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('procedure_payments', function (Blueprint $table) {
            $table->dropForeign(['shift_id']);
        });

        Schema::table('procedure_payments', function (Blueprint $table) {
            $table->foreignId('shift_id')->nullable(false)->change();
            $table->foreign('shift_id')->references('id')->on('shifts')->cascadeOnDelete();
        });
    }
};
