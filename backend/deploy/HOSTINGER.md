# Backend en Hostinger (`backend.jumechi-soft.com`)

Montado igual que `api.jumechi-soft.com`: un sitio web propio en hPanel, con la app Laravel junto a
`public_html` (fuera de la parte pública) y en `public_html` solo la puerta de entrada.

```
~/domains/backend.jumechi-soft.com/
├── maps-reus/            ← repo de GitHub (git clone)
│   └── backend/          ← app Laravel: .env, vendor/, database/database.sqlite…
└── public_html/          ← index.php (carga ../maps-reus/backend), .htaccess, robots.txt, favicon.ico
```

## 1. En hPanel (una sola vez)

1. **Sitio web:** *Sitios web → Añadir sitio web → Sitio web vacío* con el dominio
   `backend.jumechi-soft.com` (no como subdominio de `jumechi-soft.com`).
2. **PHP:** *Avanzado → Configuración de PHP* → **8.3** (mínimo 8.2), con `pdo_sqlite`, `sqlite3`,
   `mbstring`, `openssl`, `curl`, `dom` y `phar`.
3. **SSH:** *Avanzado → Acceso SSH* → activo, con tu clave pública añadida (puerto 65002).
4. **SSL:** el certificado gratuito para `backend.jumechi-soft.com`.

## 2. DNS (Netlify)

El dominio usa los DNS de Netlify, así que el registro va allí, como el de `api`:

`CNAME backend → backend.jumechi-soft.com.cdn.hstgr.net`

## 3. Instalar (por SSH)

```bash
cd ~/domains/backend.jumechi-soft.com
git clone https://github.com/mikePP03/maps-reus.git
```

Sube la base de datos desde tu PC (no está en git):

```powershell
scp -P 65002 C:\Users\Home\Desktop\Proyectos\maps-reus\backend\database\database.sqlite u743095127@212.85.9.79:domains/backend.jumechi-soft.com/maps-reus/backend/database/
```

Instala dependencias, crea `.env` (la primera vez) y prepara las cachés:

```bash
bash ~/domains/backend.jumechi-soft.com/maps-reus/backend/deploy/deploy.sh
```

## 4. Puerta de entrada en `public_html`

```bash
cd ~/domains/backend.jumechi-soft.com
cp maps-reus/backend/public/{.htaccess,robots.txt,favicon.ico} public_html/
cp maps-reus/backend/deploy/public-index.php public_html/index.php
```

Comprueba: `https://backend.jumechi-soft.com/api/v1/plana/feed` debe responder con JSON.

## 5. Tareas programadas (cron)

*hPanel → Avanzado → Trabajos cron* → personalizado, **cada minuto**:

```
cd /home/u743095127/domains/backend.jumechi-soft.com/maps-reus/backend && /opt/alt/php83/usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

Sincroniza la red de Reus a diario y reimporta el GTFS de la Generalitat cada mes.

## Actualizar después de un `git push`

```bash
bash ~/domains/backend.jumechi-soft.com/maps-reus/backend/deploy/deploy.sh
```

## Frontend

Al compilar el frontend, `VITE_REUS_API_URL=https://backend.jumechi-soft.com/api/v1/reus`. El backend
acepta peticiones de otros dominios (CORS por defecto de Laravel para `api/*`).
