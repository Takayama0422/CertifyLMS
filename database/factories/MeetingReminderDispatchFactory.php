<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\MeetingReminderWindow;
use App\Models\Meeting;
use App\Models\MeetingReminderDispatch;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MeetingReminderDispatch>
 */
class MeetingReminderDispatchFactory extends Factory
{
    protected $model = MeetingReminderDispatch::class;

    public function definition(): array
    {
        return [
            'meeting_id' => Meeting::factory(),
            'window' => MeetingReminderWindow::Eve->value,
            'user_id' => User::factory(),
        ];
    }
}
