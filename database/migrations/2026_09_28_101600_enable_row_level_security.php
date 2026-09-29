<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SEGURIDAD — Activa Row Level Security (RLS) en todas las tablas.
 * Supabase publica el esquema "public" en su API REST; con RLS activo y sin políticas,
 * nadie puede leer ni escribir por esa API. Laravel no se ve afectado porque se conecta
 * con el usuario dueño de las tablas (postgres), que no está sujeto a RLS.
 */
return new class extends Migration
{
    private array $tablas = [
        'municipios',
        'lineas',
        'estaciones',
        'linea_estacion',
        'buses',
        'accesos',
        'molinetes',
        'parqueos',
        'empleados',
        'pilotos',
        'seguridad',
        'operadores',
        'tarjetas',
        'recargas',
        'alertas_sistema',
        'transacciones_pago',
        'bitacora_auditoria',
        'viajes',
        'visitas_viaje',
        'users',
        'password_reset_tokens',
        'sessions',
        'cache',
        'cache_locks',
        'jobs',
        'job_batches',
        'failed_jobs',
        'migrations',
    ];

    public function up(): void
    {
        foreach ($this->tablas as $tabla) {
            DB::statement("ALTER TABLE {$tabla} ENABLE ROW LEVEL SECURITY");
        }
    }

    public function down(): void
    {
        foreach ($this->tablas as $tabla) {
            DB::statement("ALTER TABLE {$tabla} DISABLE ROW LEVEL SECURITY");
        }
    }
};
