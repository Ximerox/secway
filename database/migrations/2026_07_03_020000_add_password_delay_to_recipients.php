<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('message_recipients', function (Blueprint $table) {
            // Kennwort (mit APP_KEY verschlüsselt) bis zum zeitversetzten Versand;
            // wird nach dem Versand sofort auf NULL gesetzt.
            $table->text('pending_password')->nullable()->after('password_hash');
            $table->timestamp('password_due_at')->nullable()->after('pending_password');
        });
    }

    public function down(): void
    {
        Schema::table('message_recipients', function (Blueprint $table) {
            $table->dropColumn(['pending_password', 'password_due_at']);
        });
    }
};
