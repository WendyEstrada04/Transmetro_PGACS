@echo off
chcp 65001 >nul
title Transmetro - Cargar datos de primer dia
cd /d "%~dp0\.."

echo.
echo  ==========================================
echo    TRANSMETRO - datos de "primer dia de uso"
echo  ==========================================
echo.
echo  Esto reemplaza los datos de demostracion por un volumen bajo:
echo  pocos pagos, pocos buses en ruta y estaciones casi vacias.
echo  Para volver al dia normal ejecuta: php artisan db:seed --force
echo.
pause

set "TRANSMETRO_ESCALA=0.05"
php artisan db:seed --force
php artisan cache:clear

echo.
echo  Listo. Abre el sistema y revisa los paneles.
pause
