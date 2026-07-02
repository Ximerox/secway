<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('smime_certificates', function (Blueprint $table) {
            $table->id();
            // partner = nur öffentlicher Schlüssel (zum Verschlüsseln an Empfänger)
            // own     = eigenes Zertifikat mit privatem Schlüssel (Signieren/Entschlüsseln)
            $table->enum('type', ['partner', 'own']);
            $table->enum('scope', ['domain', 'address']);
            $table->string('target');                  // Domain oder E-Mail-Adresse, lowercase
            $table->mediumText('cert_pem');
            $table->mediumText('key_pem')->nullable(); // mit APP_KEY verschlüsselt, nur bei type=own
            $table->string('subject', 512)->nullable();
            $table->string('issuer', 512)->nullable();
            $table->timestamp('valid_from')->nullable();
            $table->timestamp('valid_until')->nullable()->index();
            $table->char('fingerprint', 64)->unique(); // SHA-256, hex
            $table->enum('source', ['upload', 'harvested'])->default('upload');
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->index(['target', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('smime_certificates');
    }
};
