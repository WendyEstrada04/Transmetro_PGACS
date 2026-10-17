<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Correos de los usuarios de demostración para la versión publicada:
 * administrador → admin@gmail.com y operador de San Sebastián → operador@gmail.com.
 * Solo cambia esos dos usuarios si existen; las contraseñas y los demás datos no se tocan.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')->where('email', 'admin@transmetro.test')->update(['email' => 'admin@gmail.com', 'updated_at' => now()]);
        DB::table('users')->where('email', 'operador@transmetro.test')->update(['email' => 'operador@gmail.com', 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('users')->where('email', 'admin@gmail.com')->update(['email' => 'admin@transmetro.test']);
        DB::table('users')->where('email', 'operador@gmail.com')->update(['email' => 'operador@transmetro.test']);
    }
};
