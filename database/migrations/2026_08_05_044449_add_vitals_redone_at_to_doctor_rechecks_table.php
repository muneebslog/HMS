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
        Schema::table('doctor_rechecks', function (Blueprint $table) {
            $table->timestamp('vitals_redone_at')->nullable()->after('acknowledged_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('doctor_rechecks', function (Blueprint $table) {
            $table->dropColumn('vitals_redone_at');
        });
    }
};
