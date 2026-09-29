<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Iniciar sesión · Transmetro</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="flex min-h-screen items-center justify-center bg-tm-900 px-4 font-sans text-slate-800 antialiased">
    <div class="w-full max-w-sm">
        <div class="mb-6 text-center text-white">
            <span class="mx-auto mb-3 flex h-14 w-14 items-center justify-center rounded-full bg-amber-400 text-2xl font-bold text-tm-900">T</span>
            <h1 class="text-2xl font-semibold">Transmetro</h1>
            <p class="text-sm text-tm-100">Sistema de Control</p>
        </div>

        <form method="POST" action="{{ route('login.store') }}" class="space-y-4 rounded-xl bg-white p-6 shadow-lg">
            @csrf
            <h2 class="text-lg font-semibold text-tm-900">Iniciar sesión</h2>

            @if ($errors->any())
                <div class="rounded-md border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-700">
                    {{ $errors->first() }}
                </div>
            @endif

            <div>
                <label for="email" class="mb-1 block text-sm font-medium">Correo electrónico</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="username"
                       class="w-full rounded-md border border-slate-300 px-3 py-2 focus:border-tm-500 focus:ring-2 focus:ring-tm-300 focus:outline-none">
            </div>

            <div>
                <label for="password" class="mb-1 block text-sm font-medium">Contraseña</label>
                <input id="password" name="password" type="password" required autocomplete="current-password"
                       class="w-full rounded-md border border-slate-300 px-3 py-2 focus:border-tm-500 focus:ring-2 focus:ring-tm-300 focus:outline-none">
            </div>

            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" name="recordar" value="1" class="rounded border-slate-300">
                Recordarme en este equipo
            </label>

            <button type="submit" class="w-full rounded-md bg-tm-700 px-4 py-2 font-medium text-white hover:bg-tm-900">
                Entrar
            </button>
        </form>
    </div>
</body>
</html>
