<?php

namespace App\Services;

use App\Models\Bus;
use App\Models\Molinete;
use App\Models\Tarjeta;
use App\Models\TransaccionPago;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cobro del pasaje al SUBIR al bus, en el molinete de entrada del bus (punto 18).
 * Reglas del Transmetro:
 *  - Se cobra a cada persona que sube: un pago por persona y por subida.
 *  - Solo se paga con Tarjeta Ciudadana o con tarjeta bancaria sin contacto (no efectivo).
 *  - La tarifa es la de la línea del bus.
 *  - Tarjeta de adulto mayor: 4 viajes de cortesía cada día (no acumulables); después, del saldo.
 *  - Una tarjeta Vecino nueva trae 5 viajes de bienvenida; después se descuenta del saldo.
 *  - La tarjeta debe estar activa y tener saldo suficiente.
 *  - Si el bus va lleno el molinete no deja pasar y se avisa para mandar otro bus (alerta 'bus_lleno').
 * Al guardar el pago, la base de datos (trg_transacciones_suben) suma la persona al bus y a la parada,
 * y la descuenta de la gente que espera en la estación.
 */
class CobroPasaje
{
    public function __construct(private ConteoPasajeros $conteo) {}

    /**
     * $manual: el operador registra el pase porque la tarjeta no se leyó o el validador no responde
     * (se acepta aunque el molinete no esté activo).
     */
    public function cobrar(Molinete $molinete, string $metodo, ?string $numeroTarjeta = null, bool $manual = false): TransaccionPago
    {
        if ($molinete->tipo !== 'entrada_bus') {
            throw ValidationException::withMessages(['pase' => 'El pasaje se cobra en el molinete de entrada del bus.']);
        }
        if (! $manual && $molinete->estado_operativo !== 'activo') {
            throw ValidationException::withMessages(['pase' => 'El molinete de entrada del bus no está activo.']);
        }

        $bus = $molinete->bus()->with('linea')->firstOrFail();
        $linea = $bus->linea;
        if (! $linea || $linea->estado !== 'activa') {
            throw ValidationException::withMessages(['pase' => $linea ? "La línea {$linea->codigo_linea} no está activa." : "El bus {$bus->placa} no tiene línea."]);
        }
        $parada = $this->conteo->paradaActual($bus);
        if (! $parada) {
            throw ValidationException::withMessages(['pase' => "El bus {$bus->placa} no está en una estación (no tiene un viaje en curso)."]);
        }

        $lleno = false;
        $pago = DB::transaction(function () use ($bus, $molinete, $linea, $parada, $metodo, $numeroTarjeta, &$lleno) {
            $bus = Bus::whereKey($bus->id_bus)->lockForUpdate()->first();
            if ($bus->pasajeros_a_bordo >= $bus->capacidad_maxima) {
                $lleno = true;

                return null;
            }

            $datos = [
                'id_molinete' => $molinete->id_molinete,
                'id_bus' => $bus->id_bus,
                'id_estacion' => $parada->id_estacion,
                'id_visita' => $parada->id_visita,
                'id_linea' => $linea->id_linea,
                'metodo_pago' => $metodo,
                'monto' => $linea->tarifa,
                'viaje_gratis' => false,
                'fecha_hora' => now(),
            ];

            if ($metodo === 'tarjeta_ciudadana') {
                // Bloquea la fila de la tarjeta para que dos cobros simultáneos no gasten el mismo saldo.
                // Se acepta como va impreso (00 0040 0001) o leído del código QR (0000400001).
                $tarjeta = Tarjeta::where('numero_tarjeta', Tarjeta::normalizarNumero($numeroTarjeta))->lockForUpdate()->first();

                if (! $tarjeta) {
                    throw ValidationException::withMessages(['numero_tarjeta' => 'No existe una tarjeta con ese número.']);
                }
                if ($tarjeta->estado !== 'activa') {
                    throw ValidationException::withMessages(['numero_tarjeta' => 'La tarjeta está '.mb_strtolower($tarjeta->estadoTexto()).': no se puede usar.']);
                }

                if ($tarjeta->cortesiasRestantesHoy() > 0) {
                    // Adulto mayor: viaje de cortesía del día (4 diarios, no acumulables).
                    $datos['monto'] = 0;
                    $datos['viaje_gratis'] = true;
                } elseif ($tarjeta->viajes_gratis_restantes > 0) {
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

        if ($lleno) {
            // Quedó al menos esta persona sin poder subir: se pide otro bus para la ruta.
            $this->conteo->alertaBusLleno($bus, $parada->estacion, 1);
            throw ValidationException::withMessages(['pase' => "El bus {$bus->placa} va lleno ({$bus->capacidad_maxima} pasajeros): "
                .'el molinete no deja subir a nadie más. Se avisó para enviar otro bus.']);
        }

        return $pago;
    }
}
