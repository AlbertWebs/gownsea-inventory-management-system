<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Hire;
use App\Models\Location;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Hire>
 */
class HireFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'reference' => 'HIRE-'.strtoupper($this->faker->unique()->bothify('######')),
            'idempotency_key' => (string) Str::uuid(),
            'customer_id' => Customer::factory(),
            'institution_id' => null,
            'location_id' => Location::factory(),
            'created_by' => User::factory(),
            'status' => 'reserved',
            'event_date' => now()->addWeeks(2)->toDateString(),
            'due_at' => now()->addWeeks(3),
            'deposit_minor' => 0,
        ];
    }
}
