<?php

namespace App\Http\Controllers;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StatsController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        abort_unless($request->user()->isStaff(), 403, 'Solo el personal de soporte puede ver estadísticas.');

        $byStatus = Ticket::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $byPriority = Ticket::query()->selectRaw('priority, count(*) as total')->groupBy('priority')->pluck('total', 'priority');

        return response()->json(['data' => [
            'total' => Ticket::count(),
            'by_status' => collect(TicketStatus::cases())
                ->mapWithKeys(fn ($s) => [$s->value => (int) ($byStatus[$s->value] ?? 0)]),
            'by_priority' => collect(TicketPriority::cases())
                ->mapWithKeys(fn ($p) => [$p->value => (int) ($byPriority[$p->value] ?? 0)]),
            'unassigned_active' => Ticket::whereNull('assigned_to')
                ->whereNotIn('status', [TicketStatus::Resolved->value, TicketStatus::Closed->value])
                ->count(),
        ]]);
    }
}
