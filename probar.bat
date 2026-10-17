@echo off
chcp 65001 >nul
title Transmetro - Pruebas automaticas
cd /d "%~dp0"

echo.
echo  Corriendo las pruebas automaticas (tardan varios minutos porque usan Supabase)...
echo.
rem Las pruebas leen su propia configuracion (esquema "pruebas"): se quita la configuracion guardada en cache.
call php artisan optimize:clear >nul
call php artisan test
echo.
echo  Si el sistema estaba abierto, vuelve a abrir iniciar.bat para que siga igual de rapido.
pause
