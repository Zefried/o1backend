<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use App\Models\Service;
use Carbon\Carbon;

class LeadQualificationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $businessId = 'BUS-QVTE1BKU';
        $now = Carbon::now();

        // Clear existing records to avoid duplicates on re-run
        DB::table('lead_qualifications')->truncate();

        $qualifications = [
            [
                'service_name' => 'Modular Kitchen',
                'questions'    => 'Kitchen layout and measurements=8, storage and design requirements=7, material/finish and hardware preferences=6, budget and timeline=9, location=5, name and phone number=8'
            ],
            [
                'service_name' => 'Living Room Design',
                'questions'    => 'Seating layout and space=8, entertainment unit requirements=7, lighting and decor preferences=6, budget and timeline=9, location=5, name and phone number=8'
            ],
            [
                'service_name' => 'Bedroom Design',
                'questions'    => 'Bed and wardrobe placement=8, color theme and mood=7, flooring and lighting preferences=6, budget and timeline=9, location=5, name and phone number=8'
            ],
            [
                'service_name' => 'Bathroom Design',
                'questions'    => 'Plumbing layout and size=8, sanitaryware and fittings=7, tile and finish preferences=6, budget and timeline=9, location=5, name and phone number=8'
            ],
            [
                'service_name' => 'Full Home Interior Design',
                'questions'    => 'Total rooms and floor plan=8, overall design theme=7, material preferences for all rooms=6, budget and timeline=9, location=5, name and phone number=8'
            ],
        ];

        foreach ($qualifications as $qual) {
            $service = Service::where('name', $qual['service_name'])->first();

            if ($service) {
                DB::table('lead_qualifications')->insert([
                    'business_id' => $businessId,
                    'service_id'  => $service->id,
                    'questions'   => $qual['questions'],
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ]);
            }
        }
    }
}
