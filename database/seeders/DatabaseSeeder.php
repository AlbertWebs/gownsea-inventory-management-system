<?php

namespace Database\Seeders;

use App\Models\AcademicClassification;
use App\Models\Location;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $classifications = [
            ['code' => 'COLLEGE_TVET', 'label' => 'College / TVET', 'sort_order' => 1],
            ['code' => 'CERTIFICATE', 'label' => 'Certificate', 'sort_order' => 2],
            ['code' => 'DIPLOMA', 'label' => 'Diploma', 'sort_order' => 3],
            ['code' => 'BACHELORS', 'label' => "Bachelor's Degree", 'sort_order' => 4],
            ['code' => 'MASTERS', 'label' => "Master's Degree", 'sort_order' => 5],
            ['code' => 'PHD', 'label' => 'PhD / Doctoral', 'sort_order' => 6],
        ];

        foreach ($classifications as $classification) {
            AcademicClassification::query()->updateOrCreate(['code' => $classification['code']], $classification);
        }

        Location::query()->firstOrCreate(
            ['code' => 'CENTRAL-WH'],
            ['name' => 'Central Warehouse', 'type' => 'warehouse', 'timezone' => 'Africa/Nairobi', 'is_active' => true],
        );
    }
}
