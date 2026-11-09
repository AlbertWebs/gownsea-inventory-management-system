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
        Schema::table('late_return_policies', function (Blueprint $table): void {
            $table->string('scope_type', 16)->default('default')->index();
            $table->unsignedBigInteger('scope_id')->nullable();
            $table->string('scope_reference', 100)->nullable();
            $table->index(['scope_type', 'scope_id']);
            $table->index(['scope_type', 'scope_reference']);
        });
        Schema::table('hires', function (Blueprint $table): void {
            $table->string('contract_reference', 100)->nullable()->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('hires', function (Blueprint $table): void {
            $table->dropColumn('contract_reference');
        });
        Schema::table('late_return_policies', function (Blueprint $table): void {
            $table->dropIndex(['scope_type', 'scope_id']);
            $table->dropIndex(['scope_type', 'scope_reference']);
            $table->dropColumn(['scope_type', 'scope_id', 'scope_reference']);
        });
    }
};
