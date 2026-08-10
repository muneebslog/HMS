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
        Schema::table('service_prices', function (Blueprint $table) {
            $table->unsignedInteger('token_starts_from')->default(1)->after('doctor_share');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('service_prices', function (Blueprint $table) {
            $table->dropColumn('token_starts_from');
        });
    }
};
