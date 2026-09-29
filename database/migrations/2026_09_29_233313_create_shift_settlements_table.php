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
        Schema::create('shift_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shift_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('settled_by')->constrained('users');
            $table->date('business_date');
            $table->string('period');
            $table->decimal('opening_balance', 12, 2);
            $table->decimal('cash_sales', 12, 2);
            $table->decimal('online_sales', 12, 2);
            $table->decimal('doctor_payouts', 12, 2);
            $table->decimal('expenses', 12, 2);
            $table->decimal('declared_closing_balance', 12, 2)->nullable();
            $table->decimal('expected_amount', 12, 2);
            $table->decimal('received_amount', 12, 2);
            $table->decimal('difference', 12, 2);
            $table->text('notes')->nullable();
            $table->timestamp('settled_at');
            $table->timestamps();

            $table->index('business_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shift_settlements');
    }
};
