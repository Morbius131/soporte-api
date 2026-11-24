<?php

namespace App\Enums;

enum TicketStatus: string
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case Resolved = 'resolved';
    case Closed = 'closed';

    /** @return list<self> Estados a los que se puede pasar desde el actual. */
    public function transitions(): array
    {
        return match ($this) {
            self::Open => [self::InProgress, self::Closed],
            self::InProgress => [self::Open, self::Resolved, self::Closed],
            self::Resolved => [self::InProgress, self::Closed],
            self::Closed => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->transitions(), true);
    }
}
