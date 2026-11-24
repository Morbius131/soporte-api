<?php

namespace App\Http\Controllers;

use App\Enums\TicketStatus;
use App\Http\Requests\StoreCommentRequest;
use App\Http\Resources\CommentResource;
use App\Models\Ticket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CommentController extends Controller
{
    public function index(Request $request, Ticket $ticket): AnonymousResourceCollection
    {
        Gate::authorize('view', $ticket);

        $user = $request->user();

        $comments = $ticket->comments()
            ->with('author')
            ->when(! $user->isStaff(), fn ($q) => $q->where('internal', false))
            ->orderBy('created_at')
            ->orderBy('id')
            ->paginate(20);

        return CommentResource::collection($comments);
    }

    public function store(StoreCommentRequest $request, Ticket $ticket): JsonResponse
    {
        Gate::authorize('comment', $ticket);

        $user = $request->user();
        $internal = (bool) $request->validated('internal', false);

        abort_if($internal && ! $user->isStaff(), 403, 'Solo el personal puede crear notas internas.');

        if ($ticket->status === TicketStatus::Closed) {
            throw ValidationException::withMessages([
                'ticket' => ['El ticket está cerrado y no admite comentarios.'],
            ]);
        }

        $comment = $ticket->comments()->create([
            'user_id' => $user->id,
            'body' => $request->validated('body'),
            'internal' => $internal,
        ]);

        return (new CommentResource($comment->load('author')))
            ->response()
            ->setStatusCode(201);
    }
}
