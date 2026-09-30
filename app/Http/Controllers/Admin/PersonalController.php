<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\ManejaErroresBD;
use App\Http\Controllers\Controller;
use App\Models\Acceso;
use App\Models\Bus;
use App\Models\Empleado;
use App\Models\Estacion;
use App\Models\User;
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
        $empleados = Empleado::with(['piloto.bus', 'seguridad.acceso.estacion', 'operador.estacion', 'usuario'])
            ->when($request->filled('rol'), fn ($q) => $q->where('rol', $request->rol))
            ->when($request->filled('buscar'), fn ($q) => $q->where(fn ($w) => $w
                ->where('nombre_completo', 'ilike', '%'.$request->buscar.'%')
                ->orWhere('dpi', 'like', '%'.$request->buscar.'%')))
            ->orderBy('nombre_completo')
            ->paginate(20)
            ->withQueryString();

        return view('admin.personal.index', ['empleados' => $empleados, 'roles' => self::ROLES]);
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
                $empleado->update($this->datosEmpleado($datos));
                $this->guardarRol($empleado, $datos);
            });
        } catch (QueryException $e) {
            return $this->volverConErrorBD($e);
        }

        return redirect()->route('admin.personal.index', ['rol' => $empleado->rol])->with('estado', 'Empleado actualizado.');
    }

    public function destroy(Empleado $empleado): RedirectResponse
    {
        try {
            $empleado->delete();
        } catch (QueryException $e) {
            return $this->volverConErrorBD($e);
        }

        return back()->with('estado', 'Empleado eliminado.');
    }

    private function validar(Request $request, ?Empleado $empleado = null): array
    {
        $rol = $empleado?->rol ?? $request->input('rol');
        $id = $empleado?->id_empleado;

        $reglas = [
            'rol' => $empleado ? [] : ['required', Rule::in(array_keys(self::ROLES))],
            'dpi' => ['required', 'digits:13', Rule::unique('empleados', 'dpi')->ignore($id, 'id_empleado')],
            'nombre_completo' => ['required', 'string', 'max:200'],
            'fecha_nacimiento' => ['required', 'date', 'before_or_equal:'.now()->subYears(18)->toDateString()],
            'contacto' => ['required', 'string', 'max:200'],
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
                'id_estacion_asignada' => ['required', 'uuid', 'exists:estaciones,id_estacion'],
                'email' => ['nullable', 'email', 'max:255',
                    Rule::unique('users', 'email')->ignore($empleado?->usuario?->id)],
                'password' => [$empleado?->usuario ? 'nullable' : 'required_with:email', 'nullable', 'string', 'min:8', 'max:255'],
            ],
            default => [],
        };

        $datos = $request->validate($reglas, [
            'fecha_nacimiento.before_or_equal' => 'El empleado debe ser mayor de edad.',
            'id_bus_asignado.unique' => 'Ese bus ya tiene un piloto asignado.',
            'password.required_with' => 'Escribe una contraseña para el usuario del operador.',
        ]);
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
            'contacto_encriptado' => $datos['contacto'],
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
                'id_estacion_asignada' => $datos['id_estacion_asignada'],
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

    private function datosFormulario(Empleado $empleado): array
    {
        $idBusActual = $empleado->piloto?->id_bus_asignado;

        return [
            'empleado' => $empleado,
            'roles' => self::ROLES,
            'buses' => Bus::whereDoesntHave('piloto')
                ->when($idBusActual, fn ($q) => $q->orWhere('id_bus', $idBusActual))
                ->orderBy('placa')->pluck('placa', 'id_bus')->all(),
            'accesos' => Acceso::with('estacion')->get()
                ->sortBy(fn ($a) => $a->estacion->nombre.$a->ubicacion_fisica)
                ->mapWithKeys(fn ($a) => [$a->id_acceso => "{$a->estacion->nombre} · {$a->ubicacion_fisica}"])->all(),
            'estaciones' => Estacion::orderBy('nombre')->pluck('nombre', 'id_estacion')->all(),
        ];
    }
}
