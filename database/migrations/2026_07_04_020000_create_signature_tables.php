<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signature_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->mediumText('html');
            $table->text('text_body')->nullable();
            // Verhalten, wenn bereits eine (eigene) Signatur in der Mail steckt:
            // skip = nicht erneut anhängen, replace = alte entfernen + neu einfügen
            $table->string('existing_mode', 10)->default('replace');
            $table->boolean('active')->default(false);
            $table->timestamps();
        });

        Schema::create('signature_images', function (Blueprint $table) {
            $table->id();
            $table->string('original_name');
            $table->string('path');
            $table->string('mime', 100);
            $table->unsignedInteger('size');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signature_images');
        Schema::dropIfExists('signature_templates');
    }
};
