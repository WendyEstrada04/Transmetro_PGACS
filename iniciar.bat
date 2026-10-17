@echo off
chcp 65001 >nul
title Transmetro - Sistema de Control
cd /d "%~dp0"

echo.
echo  ==========================================
echo    TRANSMETRO - iniciando el sistema
echo  ==========================================
echo.

rem Arranque automatico: la primera vez se registra en el Inicio de Windows para levantarse solo al encender la computadora.
set "ARRANQUE=%APPDATA%\Microsoft\Windows\Start Menu\Programs\Startup\Transmetro.bat"
if not exist "%ARRANQUE%" (
    > "%ARRANQUE%" echo @echo off
    >> "%ARRANQUE%" echo start "Transmetro" /min "%~f0"
    echo  Arranque automatico activado: desde ahora el sistema se levanta solo al encender la computadora.
    echo.
)

if not exist ".env" (
    echo  No se encontro el archivo .env. Copia .env.example como .env y pon tus datos de Supabase.
    goto error
)

rem Ajustes de velocidad: sesiones y cache en archivos de la computadora (no en Supabase) y conexion
rem a la base que se reutiliza entre clics. Valen aunque el archivo .env diga otra cosa.
set "SESSION_DRIVER=file"
set "CACHE_STORE=file"
set "DB_PERSISTENT=true"

if not exist "vendor" (
    echo  [1/5] Instalando dependencias de PHP...
    call composer install || goto error
) else (
    echo  [1/5] Dependencias de PHP listas.
)

if not exist "node_modules" (
    echo  [2/5] Instalando dependencias de JavaScript...
    call npm install || goto error
) else (
    echo  [2/5] Dependencias de JavaScript listas.
)

rem Archivos que ya no se usan (pantalla separada del chofer): se borran para que no estorben.
for %%F in ("app\Http\Controllers\ChoferController.php" "resources\views\chofer\ruta.blade.php" "tests\Feature\ChoferTest.php") do if exist %%F del /q %%F
if exist "resources\views\chofer" rmdir "resources\views\chofer" 2>nul

echo  [3/5] Aplicando cambios a la base de datos...
rem Se quita la configuracion guardada en cache por si cambiaste el archivo .env.
call php artisan optimize:clear >nul
call php artisan migrate --force || goto error

rem Cierra las sesiones que quedaron abiertas: el sistema siempre empieza en el inicio de sesion.
call php artisan transmetro:cerrar-sesiones >nul

echo  [4/5] Preparando estilos y optimizando...
rem Si quedo encendido "npm run dev" de antes, el archivo public\hot hace que la pagina busque los estilos en un servidor apagado.
if exist "public\hot" del /q "public\hot"
call npm run build || goto error
rem Guarda en cache la configuracion, las rutas y las vistas: cada pagina responde mas rapido.
call php artisan optimize >nul || goto error

rem Motor de la simulacion en segundo plano (en esta misma ventana): asi el servidor web queda libre
rem para atender las pantallas. Se apaga junto con el sistema al cerrar esta ventana.
start "" /b php artisan transmetro:motor >nul 2>&1

echo  [5/5] Abriendo el sistema en http://127.0.0.1:8000
echo.
echo  Deja esta ventana abierta mientras usas el sistema. Para apagarlo, cierrala o presiona Ctrl+C.
echo.
start "" cmd /c "timeout /t 3 >nul & start http://127.0.0.1:8000/login"
call php artisan serve
goto :eof

:error
echo.
echo  Algo fallo. Revisa el mensaje de arriba.
pause
