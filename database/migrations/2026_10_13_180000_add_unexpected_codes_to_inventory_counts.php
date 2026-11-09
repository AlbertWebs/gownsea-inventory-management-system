<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_counts', function (Blueprint $table): void {
            $table->json('unexpected_codes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('inventory_counts', function (Blueprint $table): void {
            $table->dropColumn('unexpected_codes');
        });
    }
};
