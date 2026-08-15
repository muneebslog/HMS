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
        Schema::table('medication_order_medicines', function (Blueprint $table): void {
            $table->string('comment')->nullable()->after('dose');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('medication_order_medicines', function (Blueprint $table): void {
            $table->dropColumn('comment');
        });
    }
};
