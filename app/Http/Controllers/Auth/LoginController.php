<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Inicio y cierre de sesión.
 * Seguridad (OWASP A07 - fallas de autenticación):
 *  - máximo 5 intentos fallidos por minuto por correo + IP;
 *  - mensaje de error genérico (no revela si el correo existe);
 *  - la sesión se regenera al entrar y se invalida al salir;
 *  - contraseñas guardadas con bcrypt (cast 'hashed' del modelo User).
 */
class LoginController extends Controller
{
    private const MAX_INTENTOS = 5;

    public function create(): View
    {
        return view('auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credenciales = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ]);

        $clave = Str::transliterate(Str::lower($credenciales['email']).'|'.$request->ip());

        if (RateLimiter::tooManyAttempts($clave, self::MAX_INTENTOS)) {
            $segundos = RateLimiter::availableIn($clave);

            throw ValidationException::withMessages([
                'email' => "Demasiados intentos fallidos. Intenta de nuevo en {$segundos} segundos.",
            ]);
        }

        if (! Auth::attempt($credenciales, $request->boolean('recordar'))) {
            RateLimiter::hit($clave, 60);

            throw ValidationException::withMessages([
                'email' => 'El correo o la contraseña no son correctos.',
            ]);
        }

        RateLimiter::clear($clave);
        $request->session()->regenerate();

        return redirect()->intended(route('panel'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
