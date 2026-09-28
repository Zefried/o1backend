<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attribute_definitions', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->unsignedBigInteger('category_id')->nullable();
            $table->text('description')->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();

            // nullable FK — attribute can be global (no category) or category-specific
            $table->foreign('category_id')
                  ->references('id')
                  ->on('categories')
                  ->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attribute_definitions');
    }
};
