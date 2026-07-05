<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('held_messages', function (Blueprint $table) {
            $table->id();
            $table->string('sender');
            $table->json('recipients');
            $table->string('subject', 500)->nullable();
            $table->unsignedBigInteger('size_bytes');
            $table->text('enc_key');                         // Crypt-verschlüsselter Datenschlüssel
            $table->string('disk_path');                     // Roh-Mail, mit Datenschlüssel verschlüsselt
            $table->string('diagnosis', 1000)->nullable();   // an welche Zertifikate verschlüsselt
            $table->string('status', 20)->default('held');   // held | released
            $table->string('release_action', 30)->nullable(); // decrypted | as_is | auto_timeout | deleted
            $table->timestamp('hold_until');                 // danach automatische Zustellung wie empfangen
            $table->unsignedInteger('retry_count')->default(0);
            $table->timestamp('released_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'hold_until']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('held_messages');
    }
};
