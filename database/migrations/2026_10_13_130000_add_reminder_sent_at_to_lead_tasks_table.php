<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lead_tasks', function (Blueprint $table): void {
            $table->timestamp('reminder_sent_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('lead_tasks', function (Blueprint $table): void {
            $table->dropColumn('reminder_sent_at');
        });
    }
};
