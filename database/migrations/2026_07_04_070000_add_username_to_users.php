<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username')->nullable()->unique()->after('name');
        });

        // Bestehende Konten: Benutzername aus dem lokalen Teil der E-Mail ableiten
        foreach (DB::table('users')->whereNull('username')->get() as $u) {
            $local = strtok((string) $u->email, '@') ?: ('user'.$u->id);
            DB::table('users')->where('id', $u->id)->update(['username' => $local]);
        }
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('username'));
    }
};
