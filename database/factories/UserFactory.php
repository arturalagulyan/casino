<?php

namespace Database\Factories;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'username' => fake()->unique()->userName(),
            'email' => fake()->unique()->safeEmail(),
            'password' => static::$password ??= Hash::make('password'),
            'status' => UserStatus::Active,
            'remember_token' => Str::random(10),
        ];
    }

    public function unconfirmed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => UserStatus::Unconfirmed,
        ]);
    }

    /**
     * Top up the wallet the User model auto-creates on `created`
     * (see App\Models\User::booted).
     */
    public function withWallet(array $attributes = ['balance' => 1000]): static
    {
        return $this->afterCreating(fn (User $user) => $user->wallet()->update($attributes));
    }
}
