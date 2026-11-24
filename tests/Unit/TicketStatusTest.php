<?php

namespace Tests\Unit;

use App\Enums\TicketStatus;
use App\Enums\UserRole;
use PHPUnit\Framework\TestCase;

class TicketStatusTest extends TestCase
{
    public function test_transiciones_permitidas(): void
    {
        $this->assertTrue(TicketStatus::Open->canTransitionTo(TicketStatus::InProgress));
        $this->assertTrue(TicketStatus::InProgress->canTransitionTo(TicketStatus::Resolved));
        $this->assertTrue(TicketStatus::Resolved->canTransitionTo(TicketStatus::Closed));
    }

    public function test_transiciones_prohibidas(): void
    {
        $this->assertFalse(TicketStatus::Open->canTransitionTo(TicketStatus::Resolved));
        $this->assertFalse(TicketStatus::Resolved->canTransitionTo(TicketStatus::Open));
    }

    public function test_un_ticket_cerrado_es_estado_final(): void
    {
        foreach (TicketStatus::cases() as $to) {
            $this->assertFalse(TicketStatus::Closed->canTransitionTo($to));
        }
    }

    public function test_solo_agentes_y_admins_son_personal(): void
    {
        $this->assertFalse(UserRole::Customer->isStaff());
        $this->assertTrue(UserRole::Agent->isStaff());
        $this->assertTrue(UserRole::Admin->isStaff());
    }
}
