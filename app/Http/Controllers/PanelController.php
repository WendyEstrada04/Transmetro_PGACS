<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Después de iniciar sesión, envía a cada usuario a la pantalla de su rol.
 */
class PanelController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        return $request->user()->esAdministrador()
            ? redirect()->route('admin.inicio')
            : redirect()->route('operador.inicio');
    }
}
