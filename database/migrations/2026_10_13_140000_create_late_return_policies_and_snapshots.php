<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('late_return_policies', function (Blueprint $table): void {
            $table->id();
            $table->string('policy_key', 32)->unique();
            $table->unsignedBigInteger('per_day_minor')->default(0);
            $table->unsignedInteger('grace_days')->default(0);
            $table->unsignedBigInteger('cap_minor')->default(0);
            $table->boolean('count_return_day')->default(false);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        DB::table('late_return_policies')->insert([
            'policy_key' => 'default',
            'per_day_minor' => (int) config('gownsea.penalties.per_day_minor', 0),
            'grace_days' => (int) config('gownsea.penalties.grace_days', 0),
            'cap_minor' => (int) config('gownsea.penalties.cap_minor', 0),
            'count_return_day' => (bool) config('gownsea.penalties.count_return_day', false),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Schema::table('hire_items', function (Blueprint $table): void {
            $table->json('penalty_policy_snapshot')->nullable()->after('penalty_assessed_minor');
        });
    }

    public function down(): void
    {
        Schema::table('hire_items', function (Blueprint $table): void {
            $table->dropColumn('penalty_policy_snapshot');
        });
        Schema::dropIfExists('late_return_policies');
    }
};
