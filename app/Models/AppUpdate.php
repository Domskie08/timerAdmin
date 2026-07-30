<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AppUpdate extends Model
{
    public const TYPE_TIMER_APP = 'timer_app';
    public const TYPE_DTIMER_WIFI = 'dtimer_wifi';

    public const PRODUCT_LABELS = [
        self::TYPE_TIMER_APP => 'Timer App',
        self::TYPE_DTIMER_WIFI => 'DTimer WiFi',
    ];

    protected $fillable = [
        'product_type',
        'title',
        'version',
        'description',
        'file_path',
        'file_name',
        'file_size',
        'external_download_url',
        'is_active',
        'published_at',
        'uploaded_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'published_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (AppUpdate $update): void {
            if (! array_key_exists((string) $update->product_type, self::PRODUCT_LABELS)) {
                $update->product_type = self::TYPE_TIMER_APP;
            }
        });
    }

    public function scopeForProduct(Builder $query, string $productType): Builder
    {
        return $query->where('product_type', $productType);
    }

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
            'productType' => $this->product_type,
            'productLabel' => self::PRODUCT_LABELS[$this->product_type] ?? 'Software',
            'title' => $this->title,
            'version' => $this->version,
            'description' => $this->description,
            'fileName' => $this->file_name,
            'fileSize' => $this->file_size,
            'externalDownloadUrl' => $this->external_download_url,
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

