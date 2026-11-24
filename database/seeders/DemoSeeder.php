<?php

namespace Database\Seeders;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Models\Comment;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Datos de demostración. Uso:  php artisan db:seed --class=DemoSeeder
 * Cuentas (contraseña "password"): admin@demo.test, agente@demo.test, cliente@demo.test
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (User::where('email', 'cliente@demo.test')->exists()) {
            $this->command?->info('Los datos de demostración ya existen.');

            return;
        }

        $make = fn (string $name, string $email, UserRole $role) => User::create([
            'name' => $name, 'email' => $email, 'password' => 'password', 'role' => $role,
        ]);

        $admin = $make('Laura Admin', 'admin@demo.test', UserRole::Admin);
        $agent = $make('Andrés Agente', 'agente@demo.test', UserRole::Agent);
        $cliente = $make('Camila Cliente', 'cliente@demo.test', UserRole::Customer);
        $otro = $make('Carlos Cliente', 'carlos@demo.test', UserRole::Customer);

        $tickets = [
            [$cliente, 'No puedo iniciar sesión', 'Al enviar el formulario aparece un error 500 desde ayer.', TicketStatus::InProgress, TicketPriority::High, $agent],
            [$cliente, 'Factura con valor incorrecto', 'La factura de octubre muestra un cobro duplicado.', TicketStatus::Open, TicketPriority::Medium, null],
            [$cliente, 'Solicitud de nuevo usuario', 'Necesito crear una cuenta para una persona nueva del equipo.', TicketStatus::Resolved, TicketPriority::Low, $agent],
            [$otro, 'La aplicación va muy lenta', 'Los reportes tardan más de un minuto en cargar.', TicketStatus::Open, TicketPriority::Urgent, null],
            [$otro, 'Error al exportar a PDF', 'El botón de exportar descarga un archivo vacío.', TicketStatus::InProgress, TicketPriority::Medium, $admin],
            [$otro, 'Cambio de correo de contacto', 'Quiero actualizar el correo asociado a mi cuenta.', TicketStatus::Closed, TicketPriority::Low, $agent],
        ];

        foreach ($tickets as [$author, $title, $description, $status, $priority, $assignee]) {
            $ticket = Ticket::create([
                'user_id' => $author->id,
                'assigned_to' => $assignee?->id,
                'title' => $title,
                'description' => $description,
                'status' => $status,
                'priority' => $priority,
                'resolved_at' => in_array($status, [TicketStatus::Resolved, TicketStatus::Closed], true) ? now() : null,
            ]);

            if ($assignee) {
                Comment::create(['ticket_id' => $ticket->id, 'user_id' => $assignee->id, 'body' => 'Estamos revisando tu caso, te avisamos pronto.']);
                Comment::create(['ticket_id' => $ticket->id, 'user_id' => $assignee->id, 'body' => 'Revisar logs del servidor y reproducir en staging.', 'internal' => true]);
            }
        }
    }
}
