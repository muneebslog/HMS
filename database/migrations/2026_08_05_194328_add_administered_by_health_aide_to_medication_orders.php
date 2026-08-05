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
            $table->foreignId('administered_by_health_aide_id')
                ->nullable()
                ->after('administered_by')
                ->constrained('health_aides')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('medication_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('administered_by_health_aide_id');
        });
    }
};
