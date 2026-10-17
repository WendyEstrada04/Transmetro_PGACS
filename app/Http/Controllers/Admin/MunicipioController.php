<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Municipio;
use Illuminate\View\View;

/**
 * Municipios donde opera el Transmetro (punto 11). Son fijos: solo se consultan.
 */
class MunicipioController extends Controller
{
    public function index(): View
    {
        return view('admin.municipios.index', [
            'municipios' => Municipio::withCount(['lineas', 'estaciones'])->orderBy('nombre')->get(),
        ]);
    }
}
