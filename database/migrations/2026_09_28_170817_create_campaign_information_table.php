<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_information', function (Blueprint $table) {
            $table->id();
            $table->string('business_id');
            $table->unsignedBigInteger('service_id')->nullable();
            $table->string('campaign_name');
            $table->string('gender')->default('both');
            $table->text('locations')->nullable();
            $table->string('campaign_link')->unique();
            $table->timestamps();

            // Setup foreign key for service_id (assuming the services table exists)
            $table->foreign('service_id')->references('id')->on('services')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_information');
    }
};
