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
        Schema::table('medication_order_medicines', function (Blueprint $table) {
            $table->dropColumn(['quantity', 'dosage_instructions']);
            $table->string('dose')->after('medicine_id');
            $table->unsignedInteger('days')->after('dose');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('medication_order_medicines', function (Blueprint $table) {
            $table->dropColumn(['dose', 'days']);
            $table->unsignedInteger('quantity')->after('medicine_id');
            $table->string('dosage_instructions')->nullable()->after('quantity');
        });
    }
};
