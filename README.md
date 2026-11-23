# Bus Reus y Camp de Tarragona

App web para moverse en autobús por Reus y el Camp de Tarragona: dices a dónde vas y te muestra qué autobús coger, desde tu ubicación, con el recorrido en el mapa.

- **Reus urbà:** red urbana de Reus Transport con llegadas en tiempo real.
- **Camp de Tarragona:** líneas interurbanas (Plana, Hispano Igualadina, HIFE…) con los horarios oficiales de la Generalitat (GTFS): todas las salidas del día, transbordos y horario completo de cada línea.

| Carpeta | Qué es |
| --- | --- |
| [`frontend/`](frontend) | TanStack Start, React 19, Tailwind CSS 4 y Leaflet. |
| [`backend/`](backend) | API en Laravel 12 (PHP 8.2+, SQLite por defecto). |

## Arrancar en local

Backend:

```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan reus:sync-network
php artisan reus:build-geometry
php artisan plana:import-gtfs
php artisan serve
```

Frontend (en otra terminal):

```bash
cd frontend
npm install
npm run dev
```

Por defecto el frontend usa la API en `http://127.0.0.1:8000/api/v1/reus` (configurable con `VITE_REUS_API_URL` en `frontend/.env`). Los detalles de cada parte están en [`frontend/README.md`](frontend/README.md) y [`backend/README.md`](backend/README.md).

Datos: Reus Transport, Generalitat de Catalunya (GTFS de autobuses interurbanos) y © colaboradores de OpenStreetMap.
