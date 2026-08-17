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
        Schema::table('vitals', function (Blueprint $table) {
            $table->decimal('temperature', 4, 1)->nullable()->change();
            $table->unsignedSmallInteger('bp_systolic')->nullable()->change();
            $table->unsignedSmallInteger('bp_diastolic')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vitals', function (Blueprint $table) {
            $table->decimal('temperature', 4, 1)->nullable(false)->change();
            $table->unsignedSmallInteger('bp_systolic')->nullable(false)->change();
            $table->unsignedSmallInteger('bp_diastolic')->nullable(false)->change();
        });
    }
};
