<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Lista del personal asignable (agentes y administradores) para el selector de asignación. */
class AgentController extends Controller
{
    public function __invoke(Request $request): AnonymousResourceCollection
    {
        abort_unless($request->user()->isStaff(), 403, 'Solo el personal de soporte puede ver esta lista.');

        $staff = User::query()
            ->whereIn('role', [UserRole::Agent->value, UserRole::Admin->value])
            ->orderBy('name')
            ->get();

        return UserResource::collection($staff);
    }
}
