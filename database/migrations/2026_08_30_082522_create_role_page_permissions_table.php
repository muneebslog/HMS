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
        Schema::create('role_page_permissions', function (Blueprint $table) {
            $table->id();
            $table->string('role');
            $table->string('route_name');
            $table->timestamps();

            $table->unique(['role', 'route_name']);
            $table->index('role');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('role_page_permissions');
    }
};
