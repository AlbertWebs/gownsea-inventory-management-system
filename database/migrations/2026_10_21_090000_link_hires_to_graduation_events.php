<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hires', function (Blueprint $table): void {
            $table->foreignId('graduation_event_id')->nullable()->after('institution_id')->constrained()->nullOnDelete();
            $table->index(['graduation_event_id', 'event_date']);
        });
    }

    public function down(): void
    {
        Schema::table('hires', function (Blueprint $table): void {
            $table->dropIndex(['graduation_event_id', 'event_date']);
            $table->dropConstrainedForeignId('graduation_event_id');
        });
    }
};
