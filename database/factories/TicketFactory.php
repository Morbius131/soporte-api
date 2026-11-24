<?php

namespace Database\Factories;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Ticket> */
class TicketFactory extends Factory
{
    protected $model = Ticket::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'assigned_to' => null,
            'title' => fake()->sentence(6),
            'description' => fake()->paragraph(),
            'status' => TicketStatus::Open,
            'priority' => TicketPriority::Medium,
        ];
    }
}
