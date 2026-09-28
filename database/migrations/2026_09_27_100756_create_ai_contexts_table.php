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
            $table->string('business_id');
            $table->string('service_name');
            $table->string('attribute_definition');
            $table->text('context');
            $table->text('prompt');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_contexts');
    }
};
