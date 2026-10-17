<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Se quita el rol de usuario "chofer": la ruta del bus y la próxima estación las ve el operador
 * en su pantalla. Se borran los usuarios de chofer y los roles vuelven a ser administrador y operador.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')->where('rol', 'chofer')->delete();
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_rol_check');
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_rol_check CHECK (rol IN ('administrador', 'operador'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_rol_check');
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_rol_check CHECK (rol IN ('administrador', 'operador', 'chofer'))");
    }
};
