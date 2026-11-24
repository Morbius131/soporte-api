<?php

namespace App\Enums;

enum UserRole: string
{
    case Customer = 'customer';
    case Agent = 'agent';
    case Admin = 'admin';

    /** Agentes y administradores forman el "personal de soporte". */
    public function isStaff(): bool
    {
        return $this !== self::Customer;
    }
}
