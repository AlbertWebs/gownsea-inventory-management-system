<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('operating_expenses', function (Blueprint $table): void {
            $table->renameColumn('approved_by', 'decided_by');
            $table->renameColumn('approved_at', 'decided_at');
        });
    }

    public function down(): void
    {
        Schema::table('operating_expenses', function (Blueprint $table): void {
            $table->renameColumn('decided_by', 'approved_by');
            $table->renameColumn('decided_at', 'approved_at');
        });
    }
};
