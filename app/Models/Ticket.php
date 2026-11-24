<?php

namespace App\Models;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ticket extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'assigned_to', 'title', 'description', 'status', 'priority', 'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => TicketStatus::class,
            'priority' => TicketPriority::class,
            'resolved_at' => 'datetime',
        ];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    /** El personal ve todos los tickets; un cliente solo los suyos. */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $user->isStaff() ? $query : $query->where('user_id', $user->id);
    }

    /** @param array{status?:string,priority?:string,assigned_to?:int|string,q?:string} $filters */
    public function scopeFilter(Builder $query, array $filters): Builder
    {
        return $query
            ->when($filters['status'] ?? null, fn (Builder $q, $v) => $q->where('status', $v))
            ->when($filters['priority'] ?? null, fn (Builder $q, $v) => $q->where('priority', $v))
            ->when($filters['assigned_to'] ?? null, fn (Builder $q, $v) => $q->where('assigned_to', $v))
            ->when($filters['q'] ?? null, fn (Builder $q, $v) => $q->where(
                fn (Builder $w) => $w->where('title', 'like', "%{$v}%")->orWhere('description', 'like', "%{$v}%")
            ));
    }
}
