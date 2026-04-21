<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NewsPost extends Model
{
    protected $fillable = [
        'title',
        'body',
        'is_pinned',
        'published_at',
        'created_by',
    ];

    protected $casts = [
        'is_pinned' => 'boolean',
        'published_at' => 'datetime',
    ];

    public function scopePubliclyVisible(Builder $query): Builder
    {
        return $query->where(function (Builder $builder): void {
            $builder
                ->whereNull('published_at')
                ->orWhere('published_at', '<=', now());
        });
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'body' => $this->body,
            'publishedAt' => $this->published_at?->toIso8601String(),
            'isPinned' => $this->is_pinned,
        ];
    }

    public function toAdminArray(): array
    {
        return [
            ...$this->toPublicArray(),
            'createdAt' => $this->created_at?->toIso8601String(),
            'createdBy' => $this->creator?->name,
        ];
    }
}

