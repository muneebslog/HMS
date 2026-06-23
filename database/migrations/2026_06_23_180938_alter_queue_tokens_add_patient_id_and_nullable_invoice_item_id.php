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
        Schema::table('queue_tokens', function (Blueprint $table) {
            $table->foreignId('invoice_item_id')->nullable()->change();
            $table->foreignId('patient_id')->nullable()->constrained()->after('invoice_item_id');
            $table->index(['patient_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('queue_tokens', function (Blueprint $table) {
            $table->dropIndex(['patient_id']);
            $table->dropForeign(['patient_id']);
            $table->dropColumn('patient_id');
            $table->foreignId('invoice_item_id')->nullable(false)->change();
        });
    }
};
