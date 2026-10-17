@echo off
chcp 65001 >nul
set "ARRANQUE=%APPDATA%\Microsoft\Windows\Start Menu\Programs\Startup\Transmetro.bat"
if exist "%ARRANQUE%" (del /q "%ARRANQUE%" & echo  Listo: el sistema ya no se levanta solo al encender la computadora.) else (echo  El arranque automatico no estaba activado.)
pause
