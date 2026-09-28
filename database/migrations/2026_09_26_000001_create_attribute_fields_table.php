<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attribute_fields', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('attribute_definition_id');
            $table->string('attribute_definition_name');          // denormalized quick ref
            $table->string('name');                               // "Min Price", "Duration"
            $table->string('slug');                               // "min_price", "duration" — unique per attribute
            $table->string('data_type')->default('string');       // string | number | boolean
            $table->unsignedInteger('sort_order')->default(0);
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();

            // Scoped unique: same slug can exist in different attribute_definitions
            $table->unique(['attribute_definition_id', 'slug']);

            $table->foreign('attribute_definition_id')
                  ->references('id')
                  ->on('attribute_definitions')
                  ->onDelete('cascade'); // fields deleted when attribute is deleted
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attribute_fields');
    }
};
