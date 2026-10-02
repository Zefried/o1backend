<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class AiContext extends Model
{
    use HasFactory;

    protected $table = 'ai_contexts';

    protected $fillable = [
        'business_id',
        'service_name',
        'attribute_definition',
        'context',
        'prompt',
        'media_resources',
    ];

    protected $casts = [
        'media_resources' => 'array',
    ];

    public function images()
    {
        return $this->morphMany(Image::class, 'imageable');
    }
}
