<?php

namespace App\Http\Controllers;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Http\Requests\StoreTicketRequest;
use App\Http\Requests\UpdateTicketRequest;
use App\Http\Resources\TicketResource;
use App\Models\Ticket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TicketController extends Controller
{
    /** Columnas por las que se permite ordenar (lista blanca). */
    private const SORTABLE = ['created_at', 'updated_at', 'title'];

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Ticket::class);

        $params = $request->validate([
            'status' => ['nullable', Rule::enum(TicketStatus::class)],
            'priority' => ['nullable', Rule::enum(TicketPriority::class)],
            'assigned_to' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:100'],
            'sort' => ['nullable', Rule::in(self::SORTABLE)],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ]);

        $perPage = min((int) ($params['per_page'] ?? 15), 100);

        $tickets = Ticket::query()
            ->visibleTo($request->user())
            ->filter($params)
            ->with(['author', 'assignee'])
            ->withCount('comments')
            ->orderBy($params['sort'] ?? 'created_at', $params['direction'] ?? 'desc')
            ->orderBy('id', 'desc')
            ->paginate($perPage)
            ->withQueryString();

        return TicketResource::collection($tickets);
    }

    public function store(StoreTicketRequest $request): JsonResponse
    {
        Gate::authorize('create', Ticket::class);

        $ticket = $request->user()->tickets()->create([
            ...$request->validated(),
            'status' => TicketStatus::Open,
            'priority' => $request->validated('priority') ?? TicketPriority::Medium,
        ]);

        return (new TicketResource($ticket->load(['author', 'assignee'])))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, Ticket $ticket): TicketResource
    {
        Gate::authorize('view', $ticket);

        $user = $request->user();

        $ticket->load([
            'author',
            'assignee',
            'comments' => function ($query) use ($user) {
                $query->with('author')->orderBy('created_at')->orderBy('id');
                if (! $user->isStaff()) {
                    $query->where('internal', false); // las notas internas no llegan al cliente
                }
            },
        ]);

        return new TicketResource($ticket);
    }

    public function update(UpdateTicketRequest $request, Ticket $ticket): TicketResource
    {
        Gate::authorize('update', $ticket);

        $data = $request->validated();

        $staffOnly = array_intersect_key($data, array_flip(['status', 'priority', 'assigned_to']));
        abort_if(
            $staffOnly !== [] && ! $request->user()->isStaff(),
            403,
            'Solo el personal de soporte puede cambiar estado, prioridad o asignación.'
        );

        if (isset($data['status'])) {
            $new = TicketStatus::from($data['status']);

            if ($new !== $ticket->status && ! $ticket->status->canTransitionTo($new)) {
                throw ValidationException::withMessages([
                    'status' => ["No se puede pasar de {$ticket->status->value} a {$new->value}."],
                ]);
            }

            $data['status'] = $new;
            $data['resolved_at'] = in_array($new, [TicketStatus::Resolved, TicketStatus::Closed], true)
                ? ($ticket->resolved_at ?? now())
                : null;
        }

        $ticket->update($data);

        return new TicketResource(
            $ticket->fresh(['author', 'assignee'])->loadCount('comments')
        );
    }

    public function destroy(Ticket $ticket): Response
    {
        Gate::authorize('delete', $ticket);

        $ticket->delete();

        return response()->noContent();
    }
}
