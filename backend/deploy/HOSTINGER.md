# Backend en Hostinger (subdominio `backend.jumechi-soft.com`)

Para hosting compartido o Cloud de Hostinger (hPanel) con SSH. El código se descarga de GitHub con
git; la base de datos SQLite se sube aparte porque no está en el repositorio.

## 1. En hPanel (una sola vez)

1. **Subdominio:** *Dominios → Subdominios* → crea `backend` en `jumechi-soft.com`. Anota la carpeta que
   te asigna (suele ser `domains/jumechi-soft.com/public_html/backend` o `domains/backend.jumechi-soft.com/public_html`).
2. **PHP:** *Avanzado → Configuración de PHP* → versión **8.3** (mínimo 8.2). Comprueba que estén
   activas `pdo_sqlite`, `sqlite3`, `mbstring`, `openssl`, `curl`, `dom` y `phar`.
3. **SSH:** *Avanzado → Acceso SSH* → actívalo y anota host, puerto (65002) y usuario.
4. **SSL:** *Seguridad → SSL* → instala el certificado gratuito para `backend.jumechi-soft.com`.

## 2. Por SSH: descargar el código

```bash
ssh -p 65002 USUARIO@IP_DEL_SERVIDOR
cd ~
git clone https://github.com/mikePP03/maps-reus.git
```

## 3. Subir la base de datos

Desde tu PC (PowerShell), con Laragon parado o sin usar la app en ese momento:

```powershell
scp -P 65002 C:\Users\Home\Desktop\Proyectos\maps-reus\backend\database\database.sqlite USUARIO@IP_DEL_SERVIDOR:maps-reus/backend/database/
```

(También vale el Administrador de archivos de hPanel: súbelo a `maps-reus/backend/database/`.)

## 4. Instalar

```bash
bash ~/maps-reus/backend/deploy/deploy.sh
```

La primera vez crea `backend/.env` a partir de `deploy/hostinger.env.example` y genera `APP_KEY`.
Revisa que `APP_URL` sea `https://backend.jumechi-soft.com`.

## 5. Carpeta pública del subdominio

Igual que `api.jumechi-soft.com`: la app Laravel vive fuera de la carpeta pública y esta solo tiene
`index.php`, `.htaccess`, `favicon.ico` y `robots.txt`. La carpeta del subdominio es
`~/domains/jumechi-soft.com/public_html/backend` (la página de muestra de Hostinger quedó en
`backend_original`).

1. Copia `.htaccess`, `favicon.ico` y `robots.txt` desde `~/maps-reus/backend/public/`.
2. Sube como `index.php` el de [`public-index.php`](public-index.php), que carga la app desde
   `/home/u743095127/maps-reus/backend` (súbelo con `scp`: escribirlo en una línea de `ssh` pierde
   las comillas).

Comprueba: `https://backend.jumechi-soft.com/api/v1/plana/feed` debe responder con JSON.

## DNS (Netlify)

El dominio usa los DNS de Netlify, así que el subdominio se declara allí, como `api`:
`CNAME backend → backend.jumechi-soft.com.cdn.hstgr.net`.

## 6. Tareas programadas (cron)

*hPanel → Avanzado → Trabajos cron* → personalizado, **cada minuto**:

```
cd /home/USUARIO/maps-reus/backend && php artisan schedule:run >> /dev/null 2>&1
```

Así se sincroniza la red de Reus a diario y se reimporta el GTFS de la Generalitat cada mes.

## Actualizar después de un `git push`

```bash
bash ~/maps-reus/backend/deploy/deploy.sh
```

## Frontend

Al compilar el frontend para producción, `VITE_REUS_API_URL` debe ser
`https://backend.jumechi-soft.com/api/v1/reus`. El backend ya acepta peticiones de otros dominios (CORS
por defecto de Laravel para `api/*`).
