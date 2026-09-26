<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\GoogleCalendarCredential;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GoogleCalendarCredential>
 */
class GoogleCalendarCredentialFactory extends Factory
{
    protected $model = GoogleCalendarCredential::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->coach(),
            'calendar_id' => 'primary',
            'access_token' => 'fake-access-token-'.fake()->uuid(),
            'refresh_token' => 'fake-refresh-token-'.fake()->uuid(),
            'token_expires_at' => now()->addHour(),
            'connected_at' => now()->subDays(fake()->numberBetween(0, 30)),
        ];
    }

    public function forCoach(User $coach): static
    {
        return $this->state(fn () => ['user_id' => $coach->id]);
    }

    /**
     * アクセストークンの有効期限が切れている状態(次回利用時にリフレッシュが必要)。
     */
    public function expired(): static
    {
        return $this->state(fn () => ['token_expires_at' => now()->subHour()]);
    }
}
