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
            $table->timestamp('medication_dismissed_at')->nullable()->after('displayed_at');
            $table->foreignId('medication_dismissed_by')->nullable()->after('medication_dismissed_at')->constrained('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('queue_tokens', function (Blueprint $table) {
            $table->dropConstrainedForeignId('medication_dismissed_by');
            $table->dropColumn('medication_dismissed_at');
        });
    }
};
