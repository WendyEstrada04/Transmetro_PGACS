<?php

namespace App\Services;

use App\Models\Linea;
use App\Models\Molinete;
use App\Models\Tarjeta;
use App\Models\TransaccionPago;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cobro del pasaje en un molinete (punto 18).
 * Reglas del Transmetro:
 *  - Solo se paga con Tarjeta Ciudadana o con tarjeta bancaria sin contacto (no efectivo).
 *  - La tarifa depende del servicio de la línea (Q1.00 paradas continuas, Q2.00 directo).
 *  - Una Tarjeta Ciudadana nueva trae 5 viajes gratis; después se descuenta del saldo.
 *  - La tarjeta debe estar activa y tener saldo suficiente.
 */
class CobroPasaje
{
    public function cobrar(Molinete $molinete, Linea $linea, string $metodo, ?string $numeroTarjeta = null): TransaccionPago
    {
        if ($molinete->estado_operativo !== 'activo') {
            throw ValidationException::withMessages(['pase' => 'El molinete de ese acceso no está activo.']);
        }
        if ($linea->estado !== 'activa') {
            throw ValidationException::withMessages(['pase' => "La línea {$linea->codigo_linea} no está activa."]);
        }

        return DB::transaction(function () use ($molinete, $linea, $metodo, $numeroTarjeta) {
            $datos = [
                'id_molinete' => $molinete->id_molinete,
                'id_linea' => $linea->id_linea,
                'metodo_pago' => $metodo,
                'monto' => $linea->tarifa,
                'viaje_gratis' => false,
            ];

            if ($metodo === 'tarjeta_ciudadana') {
                // Bloquea la fila de la tarjeta para que dos cobros simultáneos no gasten el mismo saldo.
                $tarjeta = Tarjeta::where('numero_tarjeta', strtoupper(trim((string) $numeroTarjeta)))->lockForUpdate()->first();

                if (! $tarjeta) {
                    throw ValidationException::withMessages(['numero_tarjeta' => 'No existe una tarjeta con ese número.']);
                }
                if ($tarjeta->estado !== 'activa') {
                    throw ValidationException::withMessages(['numero_tarjeta' => "La tarjeta está {$tarjeta->estado}."]);
                }

                if ($tarjeta->viajes_gratis_restantes > 0) {
                    $tarjeta->decrement('viajes_gratis_restantes');
                    $datos['monto'] = 0;
                    $datos['viaje_gratis'] = true;
                } elseif ((float) $tarjeta->saldo >= (float) $linea->tarifa) {
                    $tarjeta->decrement('saldo', (float) $linea->tarifa);
                } else {
                    throw ValidationException::withMessages([
                        'numero_tarjeta' => 'Saldo insuficiente (Q'.number_format((float) $tarjeta->saldo, 2).'). Recarga entre Q'
                            .Tarjeta::RECARGA_MINIMA.' y Q'.Tarjeta::RECARGA_MAXIMA.'.',
                    ]);
                }

                $datos['id_tarjeta'] = $tarjeta->id_tarjeta;
            }

            return TransaccionPago::create($datos);
        });
    }
}
