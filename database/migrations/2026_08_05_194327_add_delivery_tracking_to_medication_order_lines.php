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
            $table->timestamp('delivered_at')->nullable()->after('name');
            $table->foreignId('delivered_by_health_aide_id')
                ->nullable()
                ->after('delivered_at')
                ->constrained('health_aides')
                ->nullOnDelete();
        });

        Schema::table('medication_order_injections', function (Blueprint $table) {
            $table->timestamp('delivered_at')->nullable()->after('name');
            $table->foreignId('delivered_by_health_aide_id')
                ->nullable()
                ->after('delivered_at')
                ->constrained('health_aides')
                ->nullOnDelete();
        });

        Schema::table('medication_order_drips', function (Blueprint $table) {
            $table->string('status')->default('pending')->after('name');
            $table->timestamp('started_at')->nullable()->after('status');
            $table->foreignId('started_by_health_aide_id')
                ->nullable()
                ->after('started_at')
                ->constrained('health_aides')
                ->nullOnDelete();
            $table->timestamp('check_due_at')->nullable()->after('started_by_health_aide_id');
            $table->timestamp('check_notified_at')->nullable()->after('check_due_at');
            $table->timestamp('done_at')->nullable()->after('check_notified_at');
            $table->foreignId('done_by_health_aide_id')
                ->nullable()
                ->after('done_at')
                ->constrained('health_aides')
                ->nullOnDelete();
            $table->foreignId('done_by_user_id')
                ->nullable()
                ->after('done_by_health_aide_id')
                ->constrained('users')
                ->nullOnDelete();

            $table->index('status');
            $table->index('check_due_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('medication_order_medicines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('delivered_by_health_aide_id');
            $table->dropColumn('delivered_at');
        });

        Schema::table('medication_order_injections', function (Blueprint $table) {
            $table->dropConstrainedForeignId('delivered_by_health_aide_id');
            $table->dropColumn('delivered_at');
        });

        Schema::table('medication_order_drips', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropIndex(['check_due_at']);
            $table->dropConstrainedForeignId('started_by_health_aide_id');
            $table->dropConstrainedForeignId('done_by_health_aide_id');
            $table->dropConstrainedForeignId('done_by_user_id');
            $table->dropColumn([
                'status',
                'started_at',
                'check_due_at',
                'check_notified_at',
                'done_at',
            ]);
        });
    }
};
