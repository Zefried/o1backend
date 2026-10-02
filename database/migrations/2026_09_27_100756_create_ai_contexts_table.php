<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_contexts', function (Blueprint $table) {
            $table->id();
            $table->string('business_id')->index();
            $table->string('service_name')->index();
            $table->string('attribute_definition')->index();
            $table->text('context');
            $table->text('prompt');
            $table->json('media_resources')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_contexts');
    }
};
