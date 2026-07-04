<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('signature_templates', function (Blueprint $table) {
            $table->unsignedInteger('priority')->default(10)->after('name');
            // Empfängerrichtung: both | external | internal
            $table->string('direction', 10)->default('both')->after('existing_mode');
            // Absender-Einschränkung: all | users | group
            $table->string('sender_mode', 10)->default('all')->after('direction');
            $table->text('sender_users')->nullable()->after('sender_mode');
            $table->uuid('sender_group_id')->nullable()->after('sender_users');
            $table->string('sender_group_name')->nullable()->after('sender_group_id');
            $table->date('valid_from')->nullable()->after('sender_group_name');
            $table->date('valid_until')->nullable()->after('valid_from');
            // false = nach dieser Vorlage stoppen, true = weitere passende anwenden
            $table->boolean('continue_processing')->default(false)->after('valid_until');
        });

        Schema::table('entra_users', function (Blueprint $table) {
            // Entra-Gruppen (Objekt-IDs), in denen der Benutzer Mitglied ist —
            // befüllt vom Sync für Sync-Gruppen + in Vorlagen referenzierte Gruppen
            $table->json('group_ids')->nullable()->after('proxy_addresses');
        });
    }

    public function down(): void
    {
        Schema::table('signature_templates', function (Blueprint $table) {
            $table->dropColumn(['priority', 'direction', 'sender_mode', 'sender_users',
                'sender_group_id', 'sender_group_name', 'valid_from', 'valid_until', 'continue_processing']);
        });
        Schema::table('entra_users', fn (Blueprint $table) => $table->dropColumn('group_ids'));
    }
};
