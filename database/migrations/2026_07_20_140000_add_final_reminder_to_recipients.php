<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Zweite Erinnerung „X Stunden vor Löschung": eigener Zeitstempel, damit sie
 * unabhängig von der ersten Erinnerung (reminder_sent_at) genau einmal
 * versendet wird.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('message_recipients', function (Blueprint $table) {
            $table->timestamp('final_reminder_sent_at')->nullable()->after('reminder_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('message_recipients', function (Blueprint $table) {
            $table->dropColumn('final_reminder_sent_at');
        });
    }
};
