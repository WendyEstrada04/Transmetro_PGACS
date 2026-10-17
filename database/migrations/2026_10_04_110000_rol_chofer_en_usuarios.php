<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Nuevo rol de usuario: chofer. El chofer solo ve el mapa de su ruta y la próxima estación;
 * todo lo demás de su bus lo ve el operador.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_rol_check');
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_rol_check CHECK (rol IN ('administrador', 'operador', 'chofer'))");
    }

    public function down(): void
    {
        DB::table('users')->where('rol', 'chofer')->delete();
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_rol_check');
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_rol_check CHECK (rol IN ('administrador', 'operador'))");
    }
};
