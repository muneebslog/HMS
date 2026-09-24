<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Samples can also be received at the ER Station by a health aide (PIN sign-in, no user account).
     */
    public function up(): void
    {
        Schema::table('lab_invoice_items', function (Blueprint $table) {
            $table->foreignId('sample_received_by_health_aide_id')->nullable()->after('sample_received_by')->constrained('health_aides')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('lab_invoice_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sample_received_by_health_aide_id');
        });
    }
};
