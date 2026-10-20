# Sistema de Control Transmetro

Aplicación web para el control de la operación del **Transmetro de la Ciudad de Guatemala**: líneas y estaciones oficiales, flota de buses, personal, accesos, alertas de aforo, cobro con Tarjeta Ciudadana, reportes y bitácora de auditoría.

Proyecto del curso de Aseguramiento de la Calidad de Software, por Wendy Celeste Nathaly Estrada González.

**Aplicación publicada:** https://transmetro-fm62.onrender.com

| Usuario | Correo | Contraseña |
|---|---|---|
| Administrador | `admin@gmail.com` | `admin123` |
| Operador (estación San Sebastián) | `operador@gmail.com` | `operador123` |

> En el plan gratuito de Render, si el servidor estuvo apagado, la primera visita puede tardar cerca de un minuto.

## Funciones principales

- **Red oficial:** 8 líneas (1, 2, 6, 7, 12, 13, 18 y Ruta 5), 121 estaciones con su orden, distancias, horarios y trazo en el mapa.
- **Flota y personal:** buses de n a 2n por línea, parqueos, pilotos, guardias de seguridad y operadores por estación.
- **Operación en vivo:** monitoreo en el mapa, alertas de refuerzo al pasar el 150 % del aforo, espera extra cuando un bus va por debajo del 25 % y envío automático de buses de refuerzo.
- **Cobro sin efectivo:** Tarjeta Ciudadana (vecino, adulto mayor, discapacidad) y tarjeta bancaria en los molinetes del bus.
- **Reportes** de estaciones, líneas y buses asignados, con filtros y exportación a PDF.
- **Bitácora de auditoría** inalterable: quién hizo qué, cuándo y qué datos cambió.
- **Simulación** de la operación las 24 horas, con limpieza automática de los datos antiguos.

## Tecnologías

PHP 8.4 · Laravel 13 · Blade y Tailwind CSS · PostgreSQL (Supabase) · Leaflet y OpenStreetMap · Pest y Playwright · GitHub Actions · Docker en Render.

## Estructura

| Carpeta | Contenido |
|---|---|
| `app/` | Modelos, controladores y servicios (cobro de pasajes, simulador, reportes, bitácora, importador KML) |
| `database/migrations` | Estructura de la base de datos, triggers y reglas de negocio |
| `database/seeders` | Datos oficiales de la red y datos de prueba |
| `resources/views` | Pantallas de la aplicación |
| `tests/` | Pruebas automatizadas con Pest |
| `docs/` | Script de creación de la base de datos, consultas de los reportes, modelo entidad-relación y guía de publicación |
| `docker/`, `Dockerfile`, `render.yaml` | Publicación en Render |
| `bat/`, `iniciar.bat`, `probar.bat` | Arranque del sistema y de las pruebas en Windows |

## Ejecutar en una computadora (Windows)

1. Instalar PHP 8.4, Composer y Node.js.
2. Copiar `.env.example` como `.env` y escribir los datos de conexión de PostgreSQL / Supabase.
3. Ejecutar:
   ```
   composer install
   npm install
   npm run build
   php artisan key:generate
   php artisan migrate --seed
   ```
4. Abrir el sistema con **`iniciar.bat`** (o `php artisan serve`) y entrar a http://127.0.0.1:8000.

## Pruebas

```
php artisan test
```

250 pruebas automatizadas (1,183 comprobaciones) que verifican las reglas del sistema, la seguridad por roles y las reglas de la base de datos. Se ejecutan solas en GitHub Actions con cada cambio.

## Base de datos

22 tablas, 40 llaves foráneas, 17 triggers con las reglas de negocio, 1 procedimiento almacenado y seguridad por filas (RLS) en todas las tablas. El script completo está en `docs/Script_Creacion_Estructuras_BD_PostgreSQL.sql`.
