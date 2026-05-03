<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Account>
 */
class AccountFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'account_number' => fake()->unique()->numerify('##########'),
            'currency' => 'NGN',
            'balance_minor' => 0,
        ];
    }
}
