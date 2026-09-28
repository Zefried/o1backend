<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class CampaignInformation extends Model
{
    use HasFactory;

    protected $table = 'campaign_information';

    protected $fillable = [
        'business_id',
        'campaign_name',
        'gender',
        'locations',
        'campaign_link',
    ];
}
