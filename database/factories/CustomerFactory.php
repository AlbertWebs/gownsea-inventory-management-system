<?php

namespace Database\Factories;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_number' => 'CUS-'.strtoupper($this->faker->unique()->bothify('??####')),
            'type' => 'individual',
            'name' => $this->faker->name(),
            'phone' => '+2547'.$this->faker->numerify('########'),
            'email' => $this->faker->safeEmail(),
            'institution_id' => null,
            'source' => 'direct',
        ];
    }
}
