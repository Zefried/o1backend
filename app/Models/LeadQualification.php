<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LeadQualification extends Model
{
    protected $fillable = [
        'business_id',
        'service_id',
        'questions'
    ];

    public function service()
    {
        return $this->belongsTo(Service::class);
    }
}
