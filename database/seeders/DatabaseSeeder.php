<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Category;
use App\Models\Service;
use App\Models\AttributeDefinition;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // 1. Create the Admin User
        $adminUser = User::factory()->create([
            'name' => 'Admin User',
            'email' => 'zeffali7@gmail.com',
            'role' => 'admin',
        ]);

        // 2. Create the "Interior" Category
        $interiorCategory = Category::create([
            'name' => 'Interior',
            'slug' => 'interior',
        ]);

        // 3. Create the Business User (Abc interior)
        $businessUser = User::factory()->create([
            'name' => 'Abc interior',
            'email' => 'test@123',
            'phone' => '9966554485',
            'role' => 'business',
            'business_id' => 'BUS-QVTE1BKU',
            'category_id' => $interiorCategory->id,
            'bio' => 'interior design, we deal in all kind of interior design work',
        ]);

        // 3. Automatically Seed Core Services
        $services = [
            'Full Home Interior Design', 
            'Apartment Interior Design', 
            'Villa Interior Design', 
            'Independent House Interior Design', 
            'Living Room Design', 
            'Bedroom Design', 
            'Kitchen Design', 
            'Modular Kitchen', 
            'Bathroom Design', 
            'Dining Room Design', 
            'Home Office Design', 
            'Kids Room Design', 
            'Balcony / Terrace Design', 
            'Commercial Interior Design', 
            'Office Interior Design', 
            'Retail Store Interior', 
            'Restaurant Interior', 
            'Café Interior', 
            'Hotel Interior', 
            'Salon / Spa Interior', 
            'Showroom Interior', 
            'Clinic / Hospital Interior', 
            'Commercial Space Design', 
            'Space Planning', 
            '3D Interior Visualization', 
            'Furniture Design', 
            'Custom Furniture', 
            'Lighting Design',
        ];

        foreach ($services as $serviceName) {
            Service::create([
                'name'        => $serviceName,
                'slug'        => Str::slug($serviceName),
                'category_id' => $interiorCategory->id,
                'business_id' => $businessUser->business_id,
                'status'      => 'active',
            ]);
        }

        // 4. Automatically Seed Core Attributes
        $attributes = [
            'Price', 'Duration', 'Availability', 'Materials', 'Design Options', 
            'Customization', 'Process', 'Delivery', 'Installation', 'Maintenance', 
            'Warranty', 'Discount', 'Payment Options', 'Requirements', 'Scope of Work', 
            'Property Type', 'Area', 'Rooms', 'Design Style', 'Budget', 'Timeline', 
            'Service Location', 'Quotation', 'Consultation', 'Site Visit', 'Portfolio', 
            'Project Status', 'Finishes', 'Colors', 'Brand Options', 'Quality', 
            'Revision Policy', 'After Sales Support'
        ];

        foreach ($attributes as $attrName) {
            AttributeDefinition::create([
                'name'        => $attrName,
                'slug'        => Str::slug($attrName),
                'category_id' => $interiorCategory->id,
                'business_id' => $businessUser->business_id,
                'status'      => 'active',
            ]);
        }
    }
}
