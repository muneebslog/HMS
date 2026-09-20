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
        Schema::table('lab_field_ranges', function (Blueprint $table) {
            $table->string('value_low', 50)->nullable()->change();
            $table->string('value_high', 50)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('lab_field_ranges', function (Blueprint $table) {
            $table->decimal('value_low', 10, 2)->nullable()->change();
            $table->decimal('value_high', 10, 2)->nullable()->change();
        });
    }
};
