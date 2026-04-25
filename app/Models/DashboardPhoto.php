<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DashboardPhoto extends Model
{
    protected $fillable = [
        'title',
        'image_path',
        'image_name',
        'position',
        'is_visible',
        'uploaded_by',
    ];

    protected $casts = [
        'is_visible' => 'boolean',
    ];

    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('is_visible', true);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'imageName' => $this->image_name,
            'imageUrl' => route('dashboard-photos.show', $this),
            'position' => $this->position,
        ];
    }

    public function toAdminArray(): array
    {
        return [
            ...$this->toPublicArray(),
            'isVisible' => $this->is_visible,
            'uploadedBy' => $this->uploader?->name,
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
