<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;

class AttributeField extends Model
{
    use HasFactory;

    protected $table = 'attribute_fields';

    protected $fillable = [
        'attribute_definition_id',
        'attribute_definition_name',
        'name',
        'slug',
        'data_type',
        'sort_order',
        'status',
    ];

    /**
     * Parent attribute definition.
     */
    public function attributeDefinition()
    {
        return $this->belongsTo(AttributeDefinition::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
