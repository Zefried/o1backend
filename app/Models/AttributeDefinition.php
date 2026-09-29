<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;

class AttributeDefinition extends Model
{
    use HasFactory;

    protected $table = 'attribute_definitions';

    protected $fillable = [
        'business_id',
        'name',
        'slug',
        'category_id',
        'description',
        'status',
    ];

    protected static function booted(): void
    {
        static::creating(function (AttributeDefinition $attr) {
            if (empty($attr->slug)) {
                $attr->slug = Str::slug($attr->name);
            }
        });

        static::updating(function (AttributeDefinition $attr) {
            if ($attr->isDirty('name')) {
                $attr->slug = Str::slug($attr->name);
            }
        });
    }

    /**
     * The category this attribute belongs to (nullable = global).
     */
    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
