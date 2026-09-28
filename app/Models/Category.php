<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;

class Category extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'parent_id',
        'status',
    ];

    /**
     * Auto-generate slug from name before saving.
     */
    protected static function booted(): void
    {
        static::creating(function (Category $category) {
            if (empty($category->slug)) {
                $category->slug = Str::slug($category->name);
            }
        });

        static::updating(function (Category $category) {
            if ($category->isDirty('name')) {
                $category->slug = Str::slug($category->name);
            }
        });
    }

    /**
     * Parent category (many-to-one, self-referential).
     */
    public function parent()
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    /**
     * Direct children of this category.
     */
    public function children()
    {
        return $this->hasMany(Category::class, 'parent_id');
    }

    /**
     * Recursively load all nested children (unlimited depth).
     */
    public function allChildren()
    {
        return $this->hasMany(Category::class, 'parent_id')
                    ->with('allChildren');
    }

    /**
     * Scope: only root-level categories (no parent).
     */
    public function scopeRoots($query)
    {
        return $query->whereNull('parent_id');
    }

    /**
     * Scope: only active categories.
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
