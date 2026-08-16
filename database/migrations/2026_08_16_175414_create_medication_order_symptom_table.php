<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('medication_order_symptom', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medication_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('symptom_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['medication_order_id', 'symptom_id']);
        });

        DB::table('medication_orders')
            ->whereNotNull('symptom_id')
            ->orderBy('id')
            ->select('id', 'symptom_id')
            ->chunk(500, function ($orders): void {
                DB::table('medication_order_symptom')->insert(
                    $orders->map(fn ($order): array => [
                        'medication_order_id' => $order->id,
                        'symptom_id' => $order->symptom_id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ])->all()
                );
            });

        Schema::table('medication_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('symptom_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('medication_orders', function (Blueprint $table) {
            $table->foreignId('symptom_id')
                ->nullable()
                ->after('complaint_or_diagnosis')
                ->constrained()
                ->nullOnDelete();
        });

        DB::table('medication_order_symptom')
            ->orderBy('medication_order_id')
            ->orderBy('symptom_id')
            ->get()
            ->groupBy('medication_order_id')
            ->each(function ($rows, $orderId): void {
                DB::table('medication_orders')
                    ->where('id', $orderId)
                    ->update(['symptom_id' => $rows->first()->symptom_id]);
            });

        Schema::dropIfExists('medication_order_symptom');
    }
};
