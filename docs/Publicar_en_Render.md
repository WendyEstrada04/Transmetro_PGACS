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
   - `APP_URL`: `https://transmetro.onrender.com` (si Render te da otra dirección, cámbiala después en
     **Environment** y vuelve a desplegar).
5. Haz clic en **Apply**. La primera construcción tarda entre 5 y 10 minutos. Al terminar, el estado
   dice **Live**.

Al arrancar, la aplicación aplica sola los cambios pendientes a la base de datos (`php artisan migrate`),
incluidas las contraseñas nuevas de los usuarios de demostración.

## Paso 4. Probar

Abre la dirección que te dio Render (por ejemplo `https://transmetro.onrender.com`) e inicia sesión:

| Usuario       | Correo                       | Contraseña    |
|---------------|------------------------------|---------------|
| Administrador | `admin@transmetro.test`      | `admin123`    |
| Operador      | `operador@transmetro.test`   | `operador123` |

## Cómo funciona la simulación publicada

- Los buses avanzan **mientras alguien tiene abierta** la página de Simulación o el mapa de monitoreo.
  Si nadie la ve, la simulación espera; al volver a abrirla sigue desde donde estaba.
- Si la simulación aparece en pausa (por ejemplo, después de que Render reinicia el servidor),
  entra como administrador a **Simulación** y presiona **Iniciar**.
- La simulación respeta los horarios de las líneas: de noche no circulan buses.

## Cosas a saber del plan gratis de Render

- Si nadie entra durante 15 minutos, Render apaga el servidor. La siguiente visita tarda cerca de
  un minuto en cargar mientras se enciende; después responde normal.
- Cada vez que subes cambios a GitHub (`git push`), Render vuelve a publicar la aplicación sola.
- Si algo falla, en Render entra a **Logs** para ver el mensaje.

## Seguridad en la versión publicada

- `APP_DEBUG=false`: los errores no muestran detalles técnicos.
- Todas las páginas y estilos usan HTTPS.
- Las contraseñas `admin123` y `operador123` son solo para la demostración; si la aplicación se usara
  de verdad, se cambiarían por contraseñas seguras.
