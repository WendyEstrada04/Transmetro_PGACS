@echo off
chcp 65001 >nul
title Transmetro - Reiniciar datos de prueba
cd /d "%~dp0"

echo.
echo  ATENCION: esto BORRA todos los datos de la base (Supabase) y vuelve a cargar los datos de prueba.
echo  Usuarios: admin@transmetro.test y operador@transmetro.test
echo.
choice /C SN /M "  Seguro que quieres reiniciar los datos"
if errorlevel 2 goto cancelado

call php artisan migrate --force || goto error
call php artisan db:seed --force || goto error
echo.
echo  Listo: datos de prueba cargados.
pause
goto :eof

:cancelado
echo  Cancelado. No se borro nada.
pause
goto :eof

:error
echo.
echo  Algo fallo. Revisa el mensaje de arriba.
pause
