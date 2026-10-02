<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Image extends Model
{
    protected $fillable = [
        'imageable_id',
        'imageable_type',
        'image_url',
        'image_name',
        'image_hash',
        'sort_order',
        'is_primary',
    ];
}
