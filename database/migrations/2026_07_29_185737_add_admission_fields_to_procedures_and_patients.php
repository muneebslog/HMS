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
            $table->string('cnic')->nullable()->after('husband_name');
        });

        Schema::table('procedures', function (Blueprint $table) {
            $table->timestamp('admitted_at')->nullable()->after('room_number');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->dropColumn('cnic');
        });

        Schema::table('procedures', function (Blueprint $table) {
            $table->dropColumn('admitted_at');
        });
    }
};
