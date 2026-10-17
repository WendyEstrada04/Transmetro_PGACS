<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Contraseñas de los usuarios de demostración para la versión publicada:
 * administrador → admin123 y operador de San Sebastián → operador123.
 * Solo cambia esos dos usuarios si existen; los datos de la base no se tocan.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')->where('email', 'admin@transmetro.test')->update(['password' => Hash::make('admin123'), 'updated_at' => now()]);
        DB::table('users')->where('email', 'operador@transmetro.test')->update(['password' => Hash::make('operador123'), 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Las contraseñas anteriores no se restauran.
    }
};
