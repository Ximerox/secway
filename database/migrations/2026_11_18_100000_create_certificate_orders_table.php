<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Bestellungen eigener S/MIME-Zertifikate bei der PSW Group (API).
        // Schlüssel + CSR erzeugt das Gateway; der Schlüssel liegt mit APP_KEY
        // verschlüsselt hier, bis das ausgestellte Zertifikat übernommen ist.
        Schema::create('certificate_orders', function (Blueprint $table) {
            $table->id();
            $table->string('email');
            $table->string('product_number', 32);
            $table->string('product_name')->nullable();
            $table->mediumText('key_pem');             // Crypt::encryptString
            $table->text('csr_pem');
            $table->string('psw_order_number', 64)->nullable()->index();
            $table->string('psw_certificate_number', 64)->nullable();
            // Status auf Gateway-Seite: pending (noch nicht übermittelt),
            // ordered, issued (übernommen), failed, cancelled
            $table->string('status', 16)->default('pending')->index();
            $table->string('order_state', 32)->nullable();  // PSW: open/processing/...
            $table->string('cert_state', 32)->nullable();   // PSW: new/validating/valid/...
            $table->text('info')->nullable();               // z. B. Validierungshinweis
            $table->text('last_error')->nullable();
            $table->foreignId('smime_certificate_id')->nullable()->constrained('smime_certificates')->nullOnDelete();
            $table->string('ordered_by')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamps();
        });

        DB::statement("ALTER TABLE smime_certificates MODIFY source ENUM('upload','harvested','psw') NOT NULL DEFAULT 'upload'");

        Schema::table('smime_certificates', function (Blueprint $table) {
            // Zeitpunkt des letzten Exports mit privatem Schlüssel (Sicherung)
            $table->timestamp('exported_at')->nullable()->after('active');
        });
    }

    public function down(): void
    {
        Schema::table('smime_certificates', function (Blueprint $table) {
            $table->dropColumn('exported_at');
        });
        DB::statement("UPDATE smime_certificates SET source='upload' WHERE source='psw'");
        DB::statement("ALTER TABLE smime_certificates MODIFY source ENUM('upload','harvested') NOT NULL DEFAULT 'upload'");
        Schema::dropIfExists('certificate_orders');
    }
};
