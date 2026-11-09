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
        Schema::table('hire_items', function (Blueprint $table): void {
            $table->dropUnique(['physical_asset_id']);
            $table->index(['physical_asset_id', 'status'], 'hire_items_asset_status_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('hire_items', function (Blueprint $table): void {
            $table->dropIndex('hire_items_asset_status_index');
            // This rollback fails once a gown has more than one hire history row.
            $table->unique('physical_asset_id');
        });
    }
};
