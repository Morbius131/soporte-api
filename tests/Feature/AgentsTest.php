<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AgentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_el_personal_lista_agentes_y_admins_sin_clientes(): void
    {
        User::factory()->agent()->create();
        User::factory()->admin()->create();
        User::factory()->count(3)->create(); // clientes

        Sanctum::actingAs(User::factory()->agent()->create());

        $this->getJson('/api/agents')->assertOk()->assertJsonCount(3, 'data');
    }

    public function test_un_cliente_no_puede_listar_agentes(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/agents')->assertForbidden();
    }

    public function test_requiere_autenticacion(): void
    {
        $this->getJson('/api/agents')->assertUnauthorized();
    }
}
