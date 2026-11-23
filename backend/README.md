# Backend Reus Transport

API personal en Laravel 12 que descubre líneas y paradas desde `rtp.amersam.net`, conserva el orden real de recorrido y valida localmente cada combinación antes de consultar llegadas u horarios. No contiene un catálogo inventado.

## Instalación y configuración

Requiere PHP 8.2+ y Composer. Copia `.env.example` a `.env`, ejecuta `php artisan key:generate` y configura la base de datos. El proveedor se configura únicamente mediante `REUS_TRANSPORT_BASE_URL`; `REUS_SYNC_TIME` permite cambiar la hora diaria.

```bash
composer install
php artisan migrate
php artisan reus:sync-network
php artisan reus:build-geometry
```

`reus:build-geometry` calcula el trazado por calles entre paradas consecutivas (una petición por línea al servidor OSRM de OpenStreetMap, como mucho una por segundo) y lo guarda en `route_segments`. Solo pide los tramos que faltan; `--force` los recalcula y `--line=60` limita a una línea. Si un trazado da un rodeo exagerado (más del triple de la línea recta y más de 300 m de diferencia), se guarda recto para no dibujar vueltas falsas. El scheduler lo ejecuta cada día a las 04:30, después de sincronizar.

Opciones del importador: `--line=60`, `--dry-run`, `--force` y `--no-prune`. En cada línea sincronizada se reemplaza el recorrido completo por el publicado. Una sincronización completa desactiva las líneas que el proveedor ya no publica y las paradas que se quedan sin línea activa (`--no-prune` lo evita). Usa transacciones por línea, un bloqueo antisolapamiento y una pausa prudente. El scheduler registra una ejecución diaria; en producción añade `php artisan schedule:run` al cron cada minuto.

Las coordenadas pueden completarse desde un GTFS oficial sin modificar nombres ni relaciones:

```bash
php artisan reus:import-gtfs --file="C:\ruta\reus-transport.zip" --dry-run
php artisan reus:import-gtfs --file="C:\ruta\reus-transport.zip"
```

## API

- `GET /api/v1/reus/lines`
- `GET /api/v1/reus/lines/{line}`
- `GET /api/v1/reus/lines/{line}/stops`
- `GET /api/v1/reus/stops?search=...&per_page=20`
- `GET /api/v1/reus/stops/{stop}`
- `GET /api/v1/reus/stops/{stop}/lines`
- `GET /api/v1/reus/stops/{stop}/arrivals`
- `GET /api/v1/reus/arrivals?line=L60&stop=93`
- `GET /api/v1/reus/schedules?line=L60&stop=93`
- `GET /api/v1/reus/stops/nearby?latitude=...&longitude=...&radius=1`
- `GET /api/v1/reus/map-stops`
- `GET /api/v1/reus/routes/direct?origin=118&destination=13`
- `GET /api/v1/reus/journeys?from_lat=41.155&from_lng=1.106&to_stop=5` (también `from_stop`, `to_lat`/`to_lng`, `*_label` y `walk_radius` en metros)
- `GET /api/v1/reus/journeys/eta?legs[0][line]=L10&legs[0][stop]=124&legs[0][ride]=15&legs[1][line]=L31&legs[1][stop]=29&legs[1][ride]=15&walk_before=7&walk_after=1` (horas reales del viaje: si llegas a cada autobús y cuánto esperas en el transbordo)
- `GET /api/v1/reus/places?q=agro reus` (busca lugares de Reus en OpenStreetMap; 30 peticiones por minuto)
- `GET /api/v1/reus/walk-path?from_lat=...&from_lng=...&to_lat=...&to_lng=...` (camino a pie por calles dentro de Reus, en caché 30 días; `null` si el servicio no responde)
- `GET /api/v1/reus/stops/{stop}/connections?direction=from|to`

Éxito: `{"success":true,"data":{},"meta":{}}`. Error de combinación: `{"success":false,"message":"La línea L60 no pasa por la parada 125.","errors":{}}`.

Los errores 404 y de validación usan el mismo formato `{"success":false,...}`. Las llegadas de una parada completa (`/stops/{stop}/arrivals`) consultan todas sus líneas en paralelo y tienen un límite propio de 15 peticiones por minuto.

## Líneas circulares

Una línea puede pasar más de una vez por la misma parada: L10 y L11 empiezan y terminan en la parada 13. El recorrido se guarda con cada paso, identificado por `stop_sequence`, así que `/lines/L10/stops` incluye la 13 al principio y al final. `/stops/13/lines` muestra cada línea una sola vez. En `/routes/direct`, cada resultado incluye `circular` y `crosses_terminus`; este último vale `true` cuando el tramo sigue más allá de la cabecera y continúa en la vuelta siguiente.

Ejemplos solicitados:

```text
GET /api/v1/reus/lines
GET /api/v1/reus/lines/L60/stops
GET /api/v1/reus/stops/93/lines
GET /api/v1/reus/arrivals?line=L60&stop=93
GET /api/v1/reus/stops/93/arrivals
```

## Calidad

```bash
php artisan test
vendor/bin/pint --test
```

Los tests usan SQLite en memoria, fixtures pequeñas y `Http::fake()`: nunca acceden realmente al proveedor.

## Limitaciones

El proveedor no ofrece una API pública documentada y su HTML puede cambiar. El tiempo real puede devolver mensajes en vez de minutos. Las coordenadas permanecen nulas salvo que se incorpore una fuente oficial adicional (por ejemplo GTFS). La normalización inicial del scheduler ofrece horas detectadas y texto seguro, pues su estructura no está documentada. Esta integración es para uso personal y aplica caché, rate limiting y pocas peticiones.

## Cómo llegar

`/journeys` combina tramos a pie con hasta dos líneas (un transbordo en la misma parada). Parte de las 6 paradas más cercanas al origen y al destino (600 m por defecto) y ordena por un tiempo estimado: 75 m/min a pie con un 30 % de rodeo, 1,5 min por parada y 6 min por transbordo. No conoce horarios, así que no incluye esperas. Descarta los transbordos que no compensan y los autobuses más lentos que ir andando.

`/places` consulta Nominatim (OpenStreetMap) limitado al área de Reus y guarda cada búsqueda 24 h, como pide su política de uso. Se puede cambiar el servidor con `NOMINATIM_URL`.

`/journeys/eta` calcula las horas de un viaje concreto. El proveedor solo da en tiempo real el próximo autobús de cada línea en cada parada, así que se combina con el horario de esa parada: si llegas a tiempo al próximo, se usa el tiempo real; si pasa antes de que llegues (por ejemplo, en el transbordo), se toma la siguiente hora del horario, que puede ser al día siguiente (`next_day`). Las horas van en hora local (`REUS_TIMEZONE`, por defecto `Europe/Madrid`). El tiempo dentro del bus sigue siendo estimado.

## Red interurbana del Camp de Tarragona

Aparte de la red urbana de Reus, la API sirve las líneas interurbanas del **Camp de Tarragona**: todas las que tienen al menos una parada en la zona (Alt Camp, Baix Camp, Tarragonès, Priorat y Conca de Barberà), de cualquier operador (Plana, Hispano Igualadina, HIFE, Autocars del Penedès…). Se importan enteras aunque salgan de la zona. Vienen del GTFS oficial de autobuses interurbanos de la Generalitat de Catalunya; son datos abiertos y hay que citar la fuente y la fecha de actualización. Van en tablas propias, separadas de las de Reus. Las tablas y la API conservan el nombre `plana_*` / `/plana` por compatibilidad.

```bash
php artisan plana:import-gtfs                      # descarga el ZIP oficial e importa la zona
php artisan plana:import-gtfs --file=ruta.zip      # importa un ZIP ya descargado
php artisan plana:import-gtfs --agency="Plana"     # solo un operador (texto contenido en su nombre)
php artisan plana:import-gtfs --all-areas          # toda Cataluña, no solo la zona configurada
```

El importador lee el ZIP en streaming y guarda líneas, paradas, expediciones con sus horarios, calendario día a día, trazados oficiales (`shapes.txt`), avisos por expedición (`nota.txt`) y transbordos a pie entre paradas a menos de 350 m. El scheduler lo repite el día 20 de cada mes a las 05:00, porque la Generalitat actualiza el fichero mensualmente. Configurable con `PLANA_GTFS_URL`, `PLANA_AGENCY` y `services.plana.area`.

- `GET /api/v1/plana/feed` (fecha de importación, vigencia y fuente)
- `GET /api/v1/plana/lines` y `/lines/{id}` (recorrido y trazado de cada sentido)
- `GET /api/v1/plana/map-stops` y `/stops`
- `GET /api/v1/plana/stops/{id}/departures?date=2026-10-28&time=08:00` (próximas salidas según el horario)
- `GET /api/v1/plana/journeys?from_stop=…|from_lat=…&from_lng=…&to_…&date=…&time=…`

El planificador usa el algoritmo Connection Scan sobre las expediciones del día (y las del día anterior que pasan de la medianoche). Busca la mejor opción directa, con 1 y con 2 transbordos, combinando operadores. Una opción con más transbordos solo aparece si llega al menos 3 minutos antes. También da la siguiente salida equivalente. **No hay tiempo real**: los operadores no publican posiciones ni llegadas.
