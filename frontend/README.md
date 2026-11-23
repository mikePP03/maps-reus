# Mapa de autobuses de Reus (frontend)

Mapa interactivo de las paradas de Reus Transport. Consume la API de la carpeta `../backend` de este repositorio.

- Búsqueda por nombre, código o línea («Oques», «93», «60», «L60» o «línea 60»).
- Filtro por cercanía con la ubicación del navegador.
- Llegadas en tiempo real de cada parada (se refrescan cada 30 s) y horario por línea.
- «Cómo llegar»: desde tu ubicación, una parada o un lugar («Agro Reus», «Hospital») hasta otro, con tramos a pie, hasta un transbordo, el trazado en el mapa y el próximo autobús de cada tramo. Los tiempos son estimados y no incluyen esperas.

La app tiene dos páginas, que se cambian con el selector de la cabecera:

- **Reus urbà** (`/`): red urbana de Reus Transport, con llegadas en tiempo real.
- **Camp de Tarragona** (`/camp-tarragona`; `/plana` redirige aquí): líneas interurbanas de todos los operadores con paradas en el Camp de Tarragona y el Priorat (Plana, Hispano Igualadina, HIFE, Autocars del Penedès…) con los horarios oficiales de la Generalitat: paradas, próximas salidas, recorridos oficiales y «Cómo llegar» con día y hora de salida. Sin tiempo real.

Hecho con TanStack Start, React 19, Tailwind CSS 4 y Leaflet. Se generó con [Lovable](https://lovable.dev); ver `AGENTS.md`.

## Desarrollo

Requiere Node.js 22 y el backend arrancado en `http://127.0.0.1:8000` (`php artisan serve`).

```bash
npm install
npm run dev
```

En desarrollo, si no defines `VITE_REUS_API_URL`, se usa `http://127.0.0.1:8000/api/v1/reus`. Para cambiarla, copia `.env.example` a `.env` y edítala.

Con Laragon, el `.env` usa una URL relativa (`/api/v1/reus`): el virtual host `maps-reus.localhost` reenvía `/api/` al backend, así la API está en el mismo origen que la app. Esto hace que funcione igual al abrirla desde el móvil por un túnel HTTPS (ver más abajo).

## Comprobaciones

```bash
npx tsc --noEmit
npm run lint
npm run format
```

## Despliegue

El build genera un Worker de Cloudflare en `.output/` (`npm run build`). Hay dos requisitos:

1. **Definir la URL pública del backend al compilar.** Vite incrusta `VITE_REUS_API_URL` en el JavaScript durante el build; cambiarla después no tiene efecto. Sin ella, un build de producción muestra el aviso «Falta configurar VITE_REUS_API_URL».

   ```bash
   # PowerShell
   $env:VITE_REUS_API_URL="https://api.tu-dominio.com/api/v1/reus"; npm run build
   # Git Bash
   VITE_REUS_API_URL=https://api.tu-dominio.com/api/v1/reus npm run build
   ```

2. **Publicar el backend con HTTPS.** Una página servida por HTTPS no puede llamar a una API HTTP: el navegador lo bloquea como contenido mixto. El backend ya permite peticiones desde cualquier origen en `api/*` (configuración CORS por defecto de Laravel).

El endpoint de llegadas de una parada tiene un límite de 15 peticiones por minuto por IP. La aplicación refresca cada 30 s, así que queda margen.

## Móvil

En pantallas de menos de 1024 px el mapa ocupa toda la pantalla y el panel es una hoja inferior con tres alturas (plegada, media y completa): se arrastra o se toca el asa. Al elegir una parada o calcular una ruta, la hoja se coloca a media altura y el mapa encuadra el recorrido por encima de ella.

Para probarla en un móvil real hace falta HTTPS (el navegador solo da la ubicación en HTTPS o en `localhost`). Con Laragon y el frontend en marcha:

```bash
cloudflared tunnel --url http://localhost:80 --http-host-header maps-reus.localhost
```

`cloudflared` muestra una dirección `https://….trycloudflare.com` que se abre en el móvil. Es temporal, cambia en cada arranque y cualquiera que la conozca puede usar la app y la API mientras el túnel esté abierto: ciérralo con Ctrl+C al terminar.
