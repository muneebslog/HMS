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
        Schema::table('patients', function (Blueprint $table) {
            $table->string('husband_name')->nullable()->after('name');
        });

        Schema::table('procedures', function (Blueprint $table) {
            $table->date('expected_delivery_date')->nullable()->after('name');
            $table->string('room_number')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->dropColumn('husband_name');
        });

        Schema::table('procedures', function (Blueprint $table) {
            $table->dropColumn('expected_delivery_date');
            $table->string('room_number')->nullable(false)->change();
        });
    }
};
