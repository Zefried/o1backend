<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;

class Service extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'category_id',
        'description',
        'status',
    ];

    /**
     * Auto-generate slug from name before saving.
     */
    protected static function booted(): void
    {
        static::creating(function (Service $service) {
            if (empty($service->slug)) {
                $service->slug = Str::slug($service->name);
            }
        });

        static::updating(function (Service $service) {
            if ($service->isDirty('name')) {
                $service->slug = Str::slug($service->name);
            }
        });
    }

    /**
     * The category this service belongs to.
     */
    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Scope: only active services.
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
