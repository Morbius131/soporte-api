<?php

namespace Tests\Feature;

use App\Enums\TicketStatus;
use App\Models\Comment;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CommentTest extends TestCase
{
    use RefreshDatabase;

    public function test_cliente_comenta_en_su_ticket(): void
    {
        $customer = User::factory()->create();
        $ticket = Ticket::factory()->create(['user_id' => $customer->id]);
        Sanctum::actingAs($customer);

        $this->postJson("/api/tickets/{$ticket->id}/comments", ['body' => 'Sigue fallando.'])
            ->assertCreated()
            ->assertJsonPath('data.body', 'Sigue fallando.')
            ->assertJsonPath('data.internal', false);
    }

    public function test_cliente_no_comenta_en_tickets_ajenos(): void
    {
        $ticket = Ticket::factory()->create();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson("/api/tickets/{$ticket->id}/comments", ['body' => 'Hola'])->assertForbidden();
    }

    public function test_cliente_no_puede_crear_notas_internas(): void
    {
        $customer = User::factory()->create();
        $ticket = Ticket::factory()->create(['user_id' => $customer->id]);
        Sanctum::actingAs($customer);

        $this->postJson("/api/tickets/{$ticket->id}/comments", ['body' => 'Nota', 'internal' => true])
            ->assertForbidden();
    }

    public function test_agente_crea_una_nota_interna(): void
    {
        $ticket = Ticket::factory()->create();
        Sanctum::actingAs(User::factory()->agent()->create());

        $this->postJson("/api/tickets/{$ticket->id}/comments", ['body' => 'Revisar logs', 'internal' => true])
            ->assertCreated()
            ->assertJsonPath('data.internal', true);
    }

    public function test_las_notas_internas_no_se_muestran_al_cliente(): void
    {
        $customer = User::factory()->create();
        $ticket = Ticket::factory()->create(['user_id' => $customer->id]);
        Comment::factory()->create(['ticket_id' => $ticket->id, 'internal' => false]);
        Comment::factory()->create(['ticket_id' => $ticket->id, 'internal' => true]);

        Sanctum::actingAs($customer);
        $this->getJson("/api/tickets/{$ticket->id}/comments")->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/tickets/{$ticket->id}")->assertOk()->assertJsonCount(1, 'data.comments');

        Sanctum::actingAs(User::factory()->agent()->create());
        $this->getJson("/api/tickets/{$ticket->id}/comments")->assertOk()->assertJsonCount(2, 'data');
        $this->getJson("/api/tickets/{$ticket->id}")->assertOk()->assertJsonCount(2, 'data.comments');
    }

    public function test_un_ticket_cerrado_no_admite_comentarios(): void
    {
        $customer = User::factory()->create();
        $ticket = Ticket::factory()->create(['user_id' => $customer->id, 'status' => TicketStatus::Closed]);
        Sanctum::actingAs($customer);

        $this->postJson("/api/tickets/{$ticket->id}/comments", ['body' => 'Una más'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['ticket']);
    }

    public function test_el_comentario_requiere_cuerpo(): void
    {
        $customer = User::factory()->create();
        $ticket = Ticket::factory()->create(['user_id' => $customer->id]);
        Sanctum::actingAs($customer);

        $this->postJson("/api/tickets/{$ticket->id}/comments", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['body']);
    }
}
