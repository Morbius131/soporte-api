<?php

namespace Tests\Feature;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StatsTest extends TestCase
{
    use RefreshDatabase;

    public function test_el_personal_ve_los_contadores(): void
    {
        Ticket::factory()->count(2)->create();
        Ticket::factory()->create(['status' => TicketStatus::Resolved, 'priority' => TicketPriority::Urgent]);

        Sanctum::actingAs(User::factory()->agent()->create());

        $this->getJson('/api/stats')
            ->assertOk()
            ->assertJsonPath('data.total', 3)
            ->assertJsonPath('data.by_status.open', 2)
            ->assertJsonPath('data.by_status.resolved', 1)
            ->assertJsonPath('data.by_status.closed', 0)
            ->assertJsonPath('data.by_priority.urgent', 1)
            ->assertJsonPath('data.unassigned_active', 2);
    }

    public function test_un_cliente_no_puede_ver_estadisticas(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/stats')->assertForbidden();
    }

    public function test_requiere_autenticacion(): void
    {
        $this->getJson('/api/stats')->assertUnauthorized();
    }
}
