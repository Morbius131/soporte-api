<?php

namespace App\Http\Requests;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // la autorización fina vive en TicketPolicy y en el controlador
    }

    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'max:150'],
            'description' => ['sometimes', 'string', 'max:5000'],
            'priority' => ['sometimes', Rule::enum(TicketPriority::class)],
            'status' => ['sometimes', Rule::enum(TicketStatus::class)],
            // Solo se puede asignar a agentes o administradores.
            'assigned_to' => [
                'sometimes', 'nullable', 'integer',
                Rule::exists('users', 'id')->whereIn('role', [UserRole::Agent->value, UserRole::Admin->value]),
            ],
        ];
    }
}
