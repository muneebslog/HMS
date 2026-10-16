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
        Schema::create('shift_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->time('starts_at');
            $table->time('ends_at');
            $table->string('color')->default('sky');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        $now = now();

        DB::table('shift_templates')->insert([
            ['name' => 'Morning', 'starts_at' => '08:00:00', 'ends_at' => '15:00:00', 'color' => 'sky', 'is_active' => true, 'sort_order' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Evening', 'starts_at' => '15:00:00', 'ends_at' => '22:00:00', 'color' => 'indigo', 'is_active' => true, 'sort_order' => 2, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Night', 'starts_at' => '22:00:00', 'ends_at' => '08:00:00', 'color' => 'violet', 'is_active' => true, 'sort_order' => 3, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shift_templates');
    }
};
