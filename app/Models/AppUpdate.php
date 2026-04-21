<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AppUpdate extends Model
{
    protected $fillable = [
        'title',
        'version',
        'description',
        'file_path',
        'file_name',
        'file_size',
        'is_active',
        'published_at',
        'uploaded_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'published_at' => 'datetime',
    ];

    public function scopePublished(Builder $query): Builder
    {
        return $query->where(function (Builder $builder): void {
            $builder
                ->whereNull('published_at')
                ->orWhere('published_at', '<=', now());
        });
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function isPublished(): bool
    {
        return ! $this->published_at || $this->published_at->lte(now());
    }

    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'version' => $this->version,
            'description' => $this->description,
            'fileName' => $this->file_name,
            'fileSize' => $this->file_size,
            'publishedAt' => $this->published_at?->toIso8601String(),
            'downloadUrl' => route('api.v1.updates.download', $this),
        ];
    }

    public function toAdminArray(): array
    {
        return [
            ...$this->toPublicArray(),
            'isActive' => $this->is_active,
            'uploadedBy' => $this->uploader?->name,
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}

