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
            $table->dropColumn(['age_low', 'age_high']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('lab_field_ranges', function (Blueprint $table) {
            $table->unsignedSmallInteger('age_low')->nullable()->after('value_high');
            $table->unsignedSmallInteger('age_high')->nullable()->after('age_low');
        });
    }
};
