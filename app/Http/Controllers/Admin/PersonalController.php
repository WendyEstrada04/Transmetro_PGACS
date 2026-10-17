<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\ManejaErroresBD;
use App\Http\Controllers\Controller;
use App\Models\Acceso;
use App\Models\Bus;
use App\Models\Empleado;
use App\Models\Estacion;
use App\Models\User;
use App\Services\Auditoria;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Personal: pilotos (punto 9), guardias de seguridad (punto 10) y operadores (punto 16).
 * Contacto, dirección e historial educativo se guardan cifrados (cast 'encrypted').
 */
class PersonalController extends Controller
{
    use ManejaErroresBD;

    private const ROLES = ['piloto' => 'Piloto', 'seguridad' => 'Seguridad', 'operador' => 'Operador'];

    public function index(Request $request): View
    {
        // Asignación de cada empleado (bus, acceso o estación) con JOIN en la misma consulta:
        // así la pantalla no hace una consulta extra por cada relación.
        $empleados = Empleado::query()
            ->select('empleados.*', 'u.email as usuario_email')
            ->selectRaw(<<<'SQL'
                CASE empleados.rol
                    WHEN 'piloto' THEN coalesce('Bus ' || b.placa, 'Bus sin asignar')
                    WHEN 'seguridad' THEN coalesce(es.nombre || ' · ' || a.ubicacion_fisica, 'Sin acceso asignado')
                    WHEN 'operador' THEN coalesce('Estación ' || eo.nombre, 'Estación sin asignar')
                END AS asignacion
                SQL)
            ->leftJoin('pilotos as pi', 'pi.id_empleado', '=', 'empleados.id_empleado')
            ->leftJoin('buses as b', 'b.id_bus', '=', 'pi.id_bus_asignado')
            ->leftJoin('seguridad as s', 's.id_empleado', '=', 'empleados.id_empleado')
            ->leftJoin('accesos as a', 'a.id_acceso', '=', 's.id_acceso_asignado')
            ->leftJoin('estaciones as es', 'es.id_estacion', '=', 'a.id_estacion')
            ->leftJoin('operadores as o', 'o.id_empleado', '=', 'empleados.id_empleado')
            ->leftJoin('estaciones as eo', 'eo.id_estacion', '=', 'o.id_estacion_asignada')
            ->leftJoin('users as u', 'u.id_empleado', '=', 'empleados.id_empleado')
            ->when($request->filled('rol'), fn ($q) => $q->where('empleados.rol', $request->rol))
            ->when($request->filled('buscar'), fn ($q) => $q->where(fn ($w) => $w
                ->where('empleados.nombre_completo', 'ilike', '%'.$request->buscar.'%')
                ->orWhere('empleados.dpi', 'like', '%'.$request->buscar.'%')))
            ->orderBy('empleados.nombre_completo')
            ->paginate(24)
            ->withQueryString();

        return view('admin.personal.index', [
            'empleados' => $empleados,
            'roles' => self::ROLES,
            'conteos' => Empleado::selectRaw('rol, count(*) AS n')->groupBy('rol')->pluck('n', 'rol')->all(),
        ]);
    }

    public function create(Request $request): View
    {
        return view('admin.personal.form', $this->datosFormulario(new Empleado([
            'rol' => array_key_exists($request->rol, self::ROLES) ? $request->rol : 'piloto',
            'activo' => true,
        ])));
    }

    public function store(Request $request): RedirectResponse
    {
        $datos = $this->validar($request);

        try {
            DB::transaction(function () use ($datos) {
                $empleado = Empleado::create($this->datosEmpleado($datos) + ['rol' => $datos['rol']]);
                $this->guardarRol($empleado, $datos);
                Auditoria::registrar('empleados', $empleado->id_empleado, 'INSERT', null, $this->auditoria($empleado));
            });
        } catch (QueryException $e) {
            return $this->volverConErrorBD($e);
        }

        return redirect()->route('admin.personal.index', ['rol' => $datos['rol']])->with('estado', 'Empleado registrado.');
    }

    public function edit(Empleado $empleado): View
    {
        $empleado->load(['piloto', 'seguridad', 'operador', 'usuario']);

        return view('admin.personal.form', $this->datosFormulario($empleado));
    }

    public function update(Request $request, Empleado $empleado): RedirectResponse
    {
        $datos = $this->validar($request, $empleado);

        try {
            DB::transaction(function () use ($empleado, $datos) {
                $antes = $this->auditoria($empleado);
                $huella = $this->huellaPersonal($empleado);
                $empleado->update($this->datosEmpleado($datos));
                $this->guardarRol($empleado, $datos);
                $despues = $this->auditoria($empleado->refresh());
                if ($this->huellaPersonal($empleado) !== $huella) {
                    $despues['datos_personales'] = 'actualizados';
                }
                Auditoria::cambio('empleados', $empleado->id_empleado, $antes, $despues);
            });
        } catch (QueryException $e) {
            return $this->volverConErrorBD($e);
        }

        return redirect()->route('admin.personal.index', ['rol' => $empleado->rol])->with('estado', 'Empleado actualizado.');
    }

    public function destroy(Empleado $empleado): RedirectResponse
    {
        $antes = $this->auditoria($empleado);
        try {
            DB::transaction(function () use ($empleado, $antes) {
                $empleado->delete();
                Auditoria::registrar('empleados', $empleado->id_empleado, 'DELETE', $antes, null);
            });
        } catch (QueryException $e) {
            return $this->volverConErrorBD($e);
        }

        return back()->with('estado', 'Empleado eliminado.');
    }

    private function validar(Request $request, ?Empleado $empleado = null): array
    {
        $rol = $empleado?->rol ?? $request->input('rol');
        $id = $empleado?->id_empleado;
        // El DPI se puede escribir con espacios (1234 56789 0101); se guardan solo los 13 dígitos.
        $request->merge(['dpi' => preg_replace('/[\s-]/', '', (string) $request->input('dpi'))]);

        $reglas = [
            'rol' => $empleado ? [] : ['required', Rule::in(array_keys(self::ROLES))],
            'dpi' => ['required', 'digits:13', Rule::unique('empleados', 'dpi')->ignore($id, 'id_empleado')],
            'nombre_completo' => ['required', 'string', 'max:200'],
            'fecha_nacimiento' => ['required', 'date', 'before_or_equal:'.now()->subYears(18)->toDateString()],
            'telefono' => ['required', 'string', 'regex:/^\+?[0-9][0-9\s\-]{6,18}$/'],
            'correo' => ['nullable', 'email', 'max:150'],
            'direccion' => ['required', 'string', 'max:255'],
        ];

        $reglas += match ($rol) {
            'piloto' => [
                'numero_licencia' => ['required', 'string', 'max:30', Rule::unique('pilotos', 'numero_licencia')->ignore($id, 'id_empleado')],
                'tipo_licencia' => ['required', Rule::in(['A', 'B', 'C'])],
                'anios_experiencia' => ['required', 'integer', 'min:0', 'max:60'],
                'nivel_educativo' => ['required', 'string', 'max:50'],
                'historial_educativo' => ['nullable', 'string', 'max:2000'],
                'id_bus_asignado' => ['nullable', 'uuid', 'exists:buses,id_bus',
                    Rule::unique('pilotos', 'id_bus_asignado')->ignore($id, 'id_empleado')],
            ],
            'seguridad' => [
                'permisos_vigentes' => ['required', 'string', 'max:100'],
                'constancia_antecedentes_penales' => ['required', 'string', 'max:100'],
                'numero_placa_asignada' => ['nullable', 'string', 'max:15'],
                'id_acceso_asignado' => ['required', 'uuid', 'exists:accesos,id_acceso'],
            ],
            'operador' => [
                'nivel_estudios' => ['required', 'string', 'max:50'],
                'anios_experiencia_monitoreo' => ['required', 'integer', 'min:0', 'max:60'],
                'id_estacion_asignada' => ['nullable', 'uuid', 'exists:estaciones,id_estacion',
                    Rule::unique('operadores', 'id_estacion_asignada')->ignore($id, 'id_empleado')],
                'email' => ['nullable', 'email', 'max:255',
                    Rule::unique('users', 'email')->ignore($empleado?->usuario?->id)],
                'password' => [$empleado?->usuario ? 'nullable' : 'required_with:email', 'nullable', 'string', 'min:8', 'max:255'],
            ],
            default => [],
        };

        $datos = $request->validate($reglas, [
            'dpi.digits' => 'El DPI tiene exactamente 13 dígitos (por ejemplo 1234 56789 0101).',
            'fecha_nacimiento.before_or_equal' => 'El empleado debe ser mayor de edad.',
            'id_bus_asignado.unique' => 'Ese bus ya tiene un piloto asignado.',
            'id_estacion_asignada.unique' => 'Esa estación ya tiene un operador asignado.',
            'password.required_with' => 'Escribe una contraseña para el usuario del operador.',
            'telefono.regex' => 'Escribe un teléfono válido (por ejemplo 5555-1234).',
        ], ['rol' => 'puesto', 'telefono' => 'teléfono', 'correo' => 'correo electrónico']);
        $datos['rol'] = $rol;
        $datos['activo'] = $request->boolean('activo');

        return $datos;
    }

    private function datosEmpleado(array $datos): array
    {
        return [
            'dpi' => $datos['dpi'],
            'nombre_completo' => $datos['nombre_completo'],
            'fecha_nacimiento' => $datos['fecha_nacimiento'],
            'contacto_encriptado' => Empleado::armarContacto($datos['telefono'], $datos['correo'] ?? null),
            'direccion_encriptada' => $datos['direccion'],
            'activo' => $datos['activo'],
        ];
    }

    private function guardarRol(Empleado $empleado, array $datos): void
    {
        $id = ['id_empleado' => $empleado->id_empleado];

        match ($datos['rol']) {
            'piloto' => $empleado->piloto()->updateOrCreate($id, [
                'numero_licencia' => $datos['numero_licencia'],
                'tipo_licencia' => $datos['tipo_licencia'],
                'anios_experiencia' => $datos['anios_experiencia'],
                'nivel_educativo' => $datos['nivel_educativo'],
                'historial_educativo_encriptado' => $datos['historial_educativo'] ?? null,
                'id_bus_asignado' => $datos['id_bus_asignado'] ?? null,
            ]),
            'seguridad' => $empleado->seguridad()->updateOrCreate($id, [
                'permisos_vigentes' => $datos['permisos_vigentes'],
                'constancia_antecedentes_penales' => $datos['constancia_antecedentes_penales'],
                'numero_placa_asignada' => $datos['numero_placa_asignada'] ?? null,
                'id_acceso_asignado' => $datos['id_acceso_asignado'],
            ]),
            'operador' => $empleado->operador()->updateOrCreate($id, [
                'nivel_estudios' => $datos['nivel_estudios'],
                'anios_experiencia_monitoreo' => $datos['anios_experiencia_monitoreo'],
                'id_estacion_asignada' => $datos['id_estacion_asignada'] ?? null,
            ]),
        };

        // Usuario para que el operador inicie sesión en la PC de su estación.
        if ($datos['rol'] === 'operador' && ! empty($datos['email'])) {
            $usuario = $empleado->usuario ?? new User(['rol' => 'operador', 'id_empleado' => $empleado->id_empleado]);
            $usuario->name = $datos['nombre_completo'];
            $usuario->email = $datos['email'];
            if (! empty($datos['password'])) {
                $usuario->password = $datos['password'];
            }
            $usuario->rol = 'operador';
            $usuario->id_empleado = $empleado->id_empleado;
            $usuario->save();
        }
    }

    /**
     * Datos del empleado que se guardan en la bitácora: puesto, nombre, estado y asignación.
     * El DPI, el teléfono, el correo, la dirección y el historial educativo no se copian (RNF-12).
     */
    private function auditoria(Empleado $empleado): array
    {
        $empleado->load(['piloto.bus', 'seguridad.acceso.estacion', 'operador.estacion', 'usuario']);
        $asignacion = match ($empleado->rol) {
            'piloto' => $empleado->piloto?->bus?->placa,
            'seguridad' => $empleado->seguridad?->acceso ? $empleado->seguridad->acceso->estacion->nombre.' · '.$empleado->seguridad->acceso->ubicacion_fisica : null,
            'operador' => $empleado->operador?->estacion?->nombre,
            default => null,
        };

        return array_filter([
            'nombre_completo' => $empleado->nombre_completo,
            'puesto' => $empleado->rol,
            'activo' => (bool) $empleado->activo,
            'asignacion' => $asignacion,
            'usuario' => $empleado->usuario?->email,
        ], fn ($v) => $v !== null);
    }

    /** Huella de los datos personales para saber si cambiaron sin guardarlos en la bitácora. */
    private function huellaPersonal(Empleado $empleado): string
    {
        return hash('sha256', implode('|', [$empleado->dpi, $empleado->fecha_nacimiento?->toDateString(),
            $empleado->contacto_encriptado, $empleado->direccion_encriptada, $empleado->piloto?->historial_educativo_encriptado]));
    }

    private function datosFormulario(Empleado $empleado): array
    {
        $idBusActual = $empleado->piloto?->id_bus_asignado;
        $idEstacionOperador = $empleado->operador?->id_estacion_asignada;

        return [
            'empleado' => $empleado,
            'roles' => self::ROLES,
            'buses' => Bus::whereDoesntHave('piloto')
                ->when($idBusActual, fn ($q) => $q->orWhere('id_bus', $idBusActual))
                ->orderBy('placa')->pluck('placa', 'id_bus')->all(),
            'accesos' => Acceso::with('estacion')->get()
                ->sortBy(fn ($a) => $a->estacion->nombre.$a->ubicacion_fisica)
                ->mapWithKeys(fn ($a) => [$a->id_acceso => "{$a->estacion->nombre} · {$a->ubicacion_fisica}"])->all(),
            // Estaciones que todavía no tienen operador (más la que ya tiene este operador).
            'estacionesOperador' => Estacion::with('lineas:id_linea,codigo_linea')
                ->where(fn ($q) => $q->whereDoesntHave('operador'))
                ->when($idEstacionOperador, fn ($q) => $q->orWhere('id_estacion', $idEstacionOperador))
                ->orderBy('nombre')->get()
                ->mapWithKeys(fn ($e) => [$e->id_estacion => $e->nombre.($e->lineas->isNotEmpty() ? ' · '.$e->lineas->pluck('codigo_linea')->join(', ') : '')])->all(),
        ];
    }
}
