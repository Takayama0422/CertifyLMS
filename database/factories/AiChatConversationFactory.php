<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AiChatConversation;
use App\Models\Enrollment;
use App\Models\Section;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiChatConversation>
 */
class AiChatConversationFactory extends Factory
{
    protected $model = AiChatConversation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->student()->inProgress(),
            'enrollment_id' => null,
            'section_id' => null,
            'title' => fake()->realText(20),
            'title_manually_set' => false,
            'last_message_at' => null,
        ];
    }

    public function forSection(Section $section): static
    {
        return $this->state(fn () => ['section_id' => $section->id]);
    }

    public function forEnrollment(Enrollment $enrollment): static
    {
        return $this->state(fn () => ['enrollment_id' => $enrollment->id]);
    }

    public function manuallyTitled(): static
    {
        return $this->state(fn () => ['title_manually_set' => true]);
    }

    public function withMessageAt(\DateTimeInterface $at): static
    {
        return $this->state(fn () => ['last_message_at' => $at]);
    }
}
