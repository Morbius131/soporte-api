<?php

namespace Tests\Feature;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TicketTest extends TestCase
{
    use RefreshDatabase;

    private function customer(): User
    {
        return User::factory()->create();
    }

    private function agent(): User
    {
        return User::factory()->agent()->create();
    }

    public function test_requiere_autenticacion(): void
    {
        $this->getJson('/api/tickets')->assertUnauthorized();
        $this->postJson('/api/tickets', [])->assertUnauthorized();
    }

    public function test_cliente_crea_ticket_con_valores_por_defecto(): void
    {
        $customer = $this->customer();
        Sanctum::actingAs($customer);

        $this->postJson('/api/tickets', [
            'title' => 'No puedo iniciar sesión',
            'description' => 'Aparece un error 500 al enviar el formulario.',
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.priority', 'medium')
            ->assertJsonPath('data.author.id', $customer->id);

        $this->assertDatabaseHas('tickets', ['title' => 'No puedo iniciar sesión', 'user_id' => $customer->id]);
    }

    public function test_crear_ticket_valida_la_entrada(): void
    {
        Sanctum::actingAs($this->customer());

        $this->postJson('/api/tickets', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['title', 'description']);

        $this->postJson('/api/tickets', ['title' => 'x', 'description' => 'y', 'priority' => 'critica'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['priority']);
    }

    public function test_cliente_solo_lista_sus_tickets(): void
    {
        $customer = $this->customer();
        Ticket::factory()->count(2)->create(['user_id' => $customer->id]);
        Ticket::factory()->count(3)->create(); // de otros usuarios

        Sanctum::actingAs($customer);

        $this->getJson('/api/tickets')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_el_personal_ve_todos_los_tickets(): void
    {
        Ticket::factory()->count(5)->create();

        Sanctum::actingAs($this->agent());

        $this->getJson('/api/tickets')->assertOk()->assertJsonPath('meta.total', 5);
    }

    public function test_filtra_por_estado_y_prioridad(): void
    {
        Ticket::factory()->create(['status' => TicketStatus::Resolved, 'priority' => TicketPriority::High]);
        Ticket::factory()->create(['status' => TicketStatus::Resolved, 'priority' => TicketPriority::Low]);
        Ticket::factory()->create(['status' => TicketStatus::Open, 'priority' => TicketPriority::High]);

        Sanctum::actingAs($this->agent());

        $this->getJson('/api/tickets?status=resolved&priority=high')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_busca_por_texto_en_titulo_y_descripcion(): void
    {
        Ticket::factory()->create(['title' => 'Impresora rota', 'description' => 'No imprime']);
        Ticket::factory()->create(['title' => 'Correo', 'description' => 'La impresora de red falla']);
        Ticket::factory()->create(['title' => 'VPN', 'description' => 'Se corta la conexión']);

        Sanctum::actingAs($this->agent());

        $this->getJson('/api/tickets?q=impresora')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_pagina_los_resultados(): void
    {
        Ticket::factory()->count(12)->create();
        Sanctum::actingAs($this->agent());

        $this->getJson('/api/tickets?per_page=5&page=3')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.last_page', 3)
            ->assertJsonPath('meta.total', 12);
    }

    public function test_per_page_tiene_un_maximo_de_100(): void
    {
        Ticket::factory()->count(3)->create();
        Sanctum::actingAs($this->agent());

        $this->getJson('/api/tickets?per_page=500')->assertOk()->assertJsonPath('meta.per_page', 100);
    }

    public function test_ordena_por_titulo_ascendente(): void
    {
        foreach (['B', 'A', 'C'] as $title) {
            Ticket::factory()->create(['title' => $title]);
        }
        Sanctum::actingAs($this->agent());

        $this->getJson('/api/tickets?sort=title&direction=asc')
            ->assertOk()
            ->assertJsonPath('data.0.title', 'A')
            ->assertJsonPath('data.2.title', 'C');
    }

    public function test_rechaza_columnas_de_orden_fuera_de_la_lista_blanca(): void
    {
        Sanctum::actingAs($this->agent());

        $this->getJson('/api/tickets?sort=password')->assertUnprocessable()->assertJsonValidationErrors(['sort']);
        $this->getJson('/api/tickets?status=inventado')->assertUnprocessable()->assertJsonValidationErrors(['status']);
    }

    public function test_cliente_ve_su_ticket_pero_no_el_de_otro(): void
    {
        $customer = $this->customer();
        $own = Ticket::factory()->create(['user_id' => $customer->id]);
        $other = Ticket::factory()->create();

        Sanctum::actingAs($customer);

        $this->getJson("/api/tickets/{$own->id}")->assertOk()->assertJsonPath('data.id', $own->id);
        $this->getJson("/api/tickets/{$other->id}")->assertForbidden();
    }

    public function test_cliente_edita_su_ticket_mientras_esta_abierto(): void
    {
        $customer = $this->customer();
        $ticket = Ticket::factory()->create(['user_id' => $customer->id]);

        Sanctum::actingAs($customer);

        $this->patchJson("/api/tickets/{$ticket->id}", ['title' => 'Título corregido'])
            ->assertOk()
            ->assertJsonPath('data.title', 'Título corregido');
    }

    public function test_cliente_no_puede_cambiar_estado_ni_prioridad(): void
    {
        $customer = $this->customer();
        $ticket = Ticket::factory()->create(['user_id' => $customer->id]);

        Sanctum::actingAs($customer);

        $this->patchJson("/api/tickets/{$ticket->id}", ['status' => 'closed'])->assertForbidden();
        $this->patchJson("/api/tickets/{$ticket->id}", ['priority' => 'urgent'])->assertForbidden();
        $this->assertSame(TicketStatus::Open, $ticket->fresh()->status);
    }

    public function test_cliente_no_edita_un_ticket_que_ya_no_esta_abierto(): void
    {
        $customer = $this->customer();
        $ticket = Ticket::factory()->create(['user_id' => $customer->id, 'status' => TicketStatus::InProgress]);

        Sanctum::actingAs($customer);

        $this->patchJson("/api/tickets/{$ticket->id}", ['title' => 'Nuevo'])->assertForbidden();
    }

    public function test_agente_aplica_una_transicion_valida(): void
    {
        $ticket = Ticket::factory()->create();
        Sanctum::actingAs($this->agent());

        $this->patchJson("/api/tickets/{$ticket->id}", ['status' => 'in_progress'])
            ->assertOk()
            ->assertJsonPath('data.status', 'in_progress');
    }

    public function test_agente_no_puede_saltarse_estados(): void
    {
        $ticket = Ticket::factory()->create(); // open → resolved no está permitido
        Sanctum::actingAs($this->agent());

        $this->patchJson("/api/tickets/{$ticket->id}", ['status' => 'resolved'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);
    }

    public function test_resolver_un_ticket_registra_resolved_at(): void
    {
        $ticket = Ticket::factory()->create(['status' => TicketStatus::InProgress]);
        Sanctum::actingAs($this->agent());

        $this->patchJson("/api/tickets/{$ticket->id}", ['status' => 'resolved'])->assertOk();

        $this->assertNotNull($ticket->fresh()->resolved_at);
    }

    public function test_agente_asigna_el_ticket_a_un_agente(): void
    {
        $agent = $this->agent();
        $ticket = Ticket::factory()->create();
        Sanctum::actingAs($agent);

        $this->patchJson("/api/tickets/{$ticket->id}", ['assigned_to' => $agent->id])
            ->assertOk()
            ->assertJsonPath('data.assignee.id', $agent->id);
    }

    public function test_no_se_puede_asignar_a_un_cliente(): void
    {
        $ticket = Ticket::factory()->create();
        Sanctum::actingAs($this->agent());

        $this->patchJson("/api/tickets/{$ticket->id}", ['assigned_to' => $this->customer()->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['assigned_to']);
    }

    public function test_solo_el_admin_elimina_tickets(): void
    {
        $customer = $this->customer();
        $ticket = Ticket::factory()->create(['user_id' => $customer->id]);

        Sanctum::actingAs($customer);
        $this->deleteJson("/api/tickets/{$ticket->id}")->assertForbidden();

        Sanctum::actingAs($this->agent());
        $this->deleteJson("/api/tickets/{$ticket->id}")->assertForbidden();

        Sanctum::actingAs(User::factory()->admin()->create());
        $this->deleteJson("/api/tickets/{$ticket->id}")->assertNoContent();

        $this->assertDatabaseMissing('tickets', ['id' => $ticket->id]);
    }
}
