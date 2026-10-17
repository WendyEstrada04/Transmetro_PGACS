# Publicar el Sistema de Control Transmetro en la nube

La aplicación se publica en **Render** (servidor web con Docker) y sigue usando la base de datos
de **Supabase** que ya usa la laptop. Render construye la aplicación desde el repositorio de GitHub
con los archivos `Dockerfile` y `render.yaml`.

## Paso 1. Subir el código a GitHub (en la laptop)

1. Borra estos archivos viejos, que ya no se usan:
   - `app\Http\Controllers\Operador\BusController.php`
   - `resources\views\operador\bus.blade.php`
   - `resources\views\admin\tarjetas\create.blade.php`
2. Abre PowerShell en la carpeta del proyecto (`C:\Users\celes\Documents\transmetro`) y escribe:

   ```
   git add -A
   git commit -m "Bitácora y publicación en Render"
   git push
   ```

   El archivo `.env` (con tus contraseñas) **no** se sube: está excluido en `.gitignore`.

## Paso 2. Datos de Supabase y llave de la aplicación

Abre el archivo `.env` de la laptop y copia estos valores; los vas a pegar en Render:

| En Render        | De dónde sale                                                   |
|------------------|-----------------------------------------------------------------|
| `APP_KEY`        | la línea `APP_KEY=base64:...` del `.env` (todo lo que va después del `=`) |
| `DB_HOST`        | la línea `DB_HOST` (termina en `pooler.supabase.com`)           |
| `DB_USERNAME`    | la línea `DB_USERNAME` (empieza con `postgres.`)                |
| `DB_PASSWORD`    | la línea `DB_PASSWORD`                                          |

Usa la conexión **Session pooler** (puerto 5432), que es la que ya tiene el `.env`.

## Paso 3. Crear el servicio en Render

1. Entra a <https://render.com> y crea la cuenta con **"Sign in with GitHub"**.
2. Haz clic en **New → Blueprint**.
3. Elige el repositorio **Transmetro_PGACS**. Render lee el archivo `render.yaml` y muestra el servicio
   **transmetro** (plan gratis, región Virginia, cerca de Supabase).
4. Llena los datos que pide:
   - `APP_KEY`, `DB_HOST`, `DB_USERNAME`, `DB_PASSWORD`: los del paso 2.
   - `APP_URL`: `https://transmetro-fm62.onrender.com` (si Render te da otra dirección, cámbiala después en
     **Environment** y vuelve a desplegar).
5. Haz clic en **Apply**. La primera construcción tarda entre 5 y 10 minutos. Al terminar, el estado
   dice **Live**.

Al arrancar, la aplicación aplica sola los cambios pendientes a la base de datos (`php artisan migrate`),
incluidas las contraseñas nuevas de los usuarios de demostración.

## Paso 4. Probar

Abre la dirección que te dio Render (por ejemplo `https://transmetro-fm62.onrender.com`) e inicia sesión:

| Usuario       | Correo                       | Contraseña    |
|---------------|------------------------------|---------------|
| Administrador | `admin@gmail.com`      | `admin123`    |
| Operador      | `operador@gmail.com`   | `operador123` |

## Cómo funciona la simulación publicada (las 24 horas)

- **Siempre encendida:** arranca encendida y su estado se guarda en la base de datos, así que sigue encendida
  aunque Render reinicie el servidor. Solo se apaga si un administrador presiona **Pausar**.
- **Calcula sola, sin páginas abiertas:** junto con la aplicación arranca un motor en segundo plano
  (`php artisan transmetro:motor`, lo inicia `docker/iniciar.sh`).
  - Si alguien tiene abierta una pantalla del sistema, hace un ciclo cada ~10 segundos y los buses se ven avanzar.
  - Si nadie la ve, hace un ciclo cada 3 minutos: la operación sigue avanzando con poca transferencia de datos.
  - La simulación respeta los horarios de las líneas: de noche no circulan buses.
- **Servidor siempre despierto:** cada 10 minutos el motor visita la página `/up` de la aplicación. Render cuenta
  esa visita como actividad y no apaga el servidor. Un servicio gratis encendido todo el mes usa unas 744 de las
  750 horas gratis de Render, por eso solo debe haber **un** servicio en la cuenta.
- **Limpieza automática:** cada hora se borran los pagos, bajadas, viajes terminados y alertas cerradas con más de
  2 días (`TRANSMETRO_RETENCION_HORAS=48`). Si la base pasa de 350 MB, se conservan solo las últimas 12 horas.
  No se borran tarjetas, saldos, recargas, personal, buses, líneas ni la bitácora. Los reportes del día siguen
  completos; el historial solo cubre los últimos días.
- **Límites gratis de Supabase:** 500 MB de base de datos y 5 GB de transferencia al mes. El motor está ajustado para
  gastar unos 2 GB al mes sin nadie mirando; cada hora con el sistema abierto gasta unos 40 MB más.
- Limpieza manual: `php artisan transmetro:limpiar --horas=24`.

### Monitoreo opcional con UptimeRobot (recomendado)

Sirve de respaldo para despertar el servidor si Render lo reinicia, y mide la disponibilidad (RNF-03):

1. Crea una cuenta gratis en <https://uptimerobot.com>.
2. **+ New monitor** → tipo **HTTP(s)**.
3. URL: `https://transmetro-fm62.onrender.com/up` · intervalo: **5 minutes**.
4. Guarda. UptimeRobot te avisa por correo si la aplicación deja de responder.

## Cosas a saber del plan gratis de Render

- Normalmente Render apaga el servidor si nadie entra durante 15 minutos; el motor lo evita visitando `/up`.
  Si alguna vez se apaga (por ejemplo, por mantenimiento de Render), la siguiente visita tarda cerca de un
  minuto en cargar mientras se enciende; después responde normal.
- Cada vez que subes cambios a GitHub (`git push`), Render vuelve a publicar la aplicación sola.
- Si algo falla, en Render entra a **Logs** para ver el mensaje.

## Seguridad en la versión publicada

- `APP_DEBUG=false`: los errores no muestran detalles técnicos.
- Todas las páginas y estilos usan HTTPS.
- Las contraseñas `admin123` y `operador123` son solo para la demostración; si la aplicación se usara
  de verdad, se cambiarían por contraseñas seguras.
