<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Acceso denegado · Transmetro</title>
    @vite(['resources/css/app.css'])
</head>
<body class="flex min-h-screen items-center justify-center bg-tm-50 px-4 font-sans text-slate-800">
    <div class="max-w-md rounded-xl bg-white p-8 text-center shadow">
        <p class="text-5xl font-semibold text-tm-900">403</p>
        <h1 class="mt-2 text-lg font-semibold">Acceso denegado</h1>
        <p class="mt-2 text-sm text-slate-600">{{ $exception->getMessage() ?: 'No tienes permiso para entrar a esta sección.' }}</p>
        <a href="{{ url('/') }}" class="mt-6 inline-block rounded-md bg-tm-700 px-4 py-2 text-sm font-medium text-white hover:bg-tm-900">Volver al inicio</a>
    </div>
</body>
</html>
