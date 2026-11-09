<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mpesa_stk_pushes', function (Blueprint $table): void {
            $table->timestamp('last_status_check_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('mpesa_stk_pushes', function (Blueprint $table): void {
            $table->dropIndex(['last_status_check_at']);
            $table->dropColumn('last_status_check_at');
        });
    }
};
