<?php

namespace App\Policies;

use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Models\Ticket;
use App\Models\User;

class TicketPolicy
{
    public function viewAny(User $user): bool
    {
        return true; // el filtrado por rol se hace en Ticket::visibleTo()
    }

    public function view(User $user, Ticket $ticket): bool
    {
        return $user->isStaff() || $ticket->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    /** El personal edita siempre; el autor solo mientras el ticket sigue abierto. */
    public function update(User $user, Ticket $ticket): bool
    {
        return $user->isStaff()
            || ($ticket->user_id === $user->id && $ticket->status === TicketStatus::Open);
    }

    public function delete(User $user, Ticket $ticket): bool
    {
        return $user->role === UserRole::Admin;
    }

    public function comment(User $user, Ticket $ticket): bool
    {
        return $this->view($user, $ticket);
    }
}
