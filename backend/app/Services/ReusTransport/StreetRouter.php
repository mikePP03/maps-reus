<?php

namespace App\Services\ReusTransport;

use App\Exceptions\ProviderException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Trazados por calles con OSRM (servidor de FOSSGIS sobre OpenStreetMap). El perfil «car» sirve
 * para los autobuses y el «foot» para los tramos a pie. El servidor es gratuito y de uso moderado:
 * los trazados de autobús se calculan una vez y se guardan, y los de a pie se cachean.
 */
class StreetRouter
{
    /** Puntos por petición; las líneas más largas se piden en varios trozos. */
    private const MAX_WAYPOINTS = 50;

    /**
     * Trazado entre cada par de puntos consecutivos, en orden.
     *
     * @param  'car'|'foot'  $profile
     * @param  list<array{0:float,1:float}>  $points  [lat, lng]
     * @return list<array{geometry:list<array{0:float,1:float}>,meters:int}>
     */
    public function legs(string $profile, array $points): array
    {
        $legs = [];
        // Trozos que se solapan en un punto para que no falte el tramo entre ellos.
        for ($start = 0; $start < count($points) - 1; $start += self::MAX_WAYPOINTS - 1) {
            $legs = [...$legs, ...$this->request($profile, array_slice($points, $start, self::MAX_WAYPOINTS))];
        }

        return $legs;
    }

    /**
     * Camino a pie entre dos puntos, o null si el servicio no responde (se dibujará recto).
     *
     * @return array{geometry:list<array{0:float,1:float}>,meters:int}|null
     */
    public function walk(float $fromLat, float $fromLng, float $toLat, float $toLng): ?array
    {
        $key = sprintf('reus:walk:%.5f,%.5f:%.5f,%.5f', $fromLat, $fromLng, $toLat, $toLng);
        if (($cached = Cache::get($key)) !== null) {
            return $cached;
        }
        try {
            $leg = $this->request('foot', [[$fromLat, $fromLng], [$toLat, $toLng]])[0];
        } catch (ProviderException) {
            return null;
        }
        Cache::put($key, $leg, now()->addDays(30));

        return $leg;
    }

    /**
     * @param  list<array{0:float,1:float}>  $points
     * @return list<array{geometry:list<array{0:float,1:float}>,meters:int}>
     */
    private function request(string $profile, array $points): array
    {
        $coordinates = implode(';', array_map(fn ($point) => sprintf('%.6f,%.6f', $point[1], $point[0]), $points));
        try {
            $route = Http::baseUrl(rtrim((string) config('services.osm_routing.url'), '/'))
                ->timeout(15)
                ->connectTimeout(4)
                ->withUserAgent('ReusBusPersonalApp/1.0 ('.config('app.url').')')
                ->acceptJson()
                ->get("/routed-$profile/route/v1/driving/$coordinates", [
                    'overview' => 'false',
                    'steps' => 'true',
                    'geometries' => 'geojson',
                ])
                ->throw()
                ->json();
        } catch (Throwable $e) {
            throw new ProviderException('No se pudo consultar el servicio de rutas.', previous: $e);
        }

        $legs = $route['routes'][0]['legs'] ?? null;
        if (($route['code'] ?? null) !== 'Ok' || ! is_array($legs) || count($legs) !== count($points) - 1) {
            throw new ProviderException('El servicio de rutas devolvió una respuesta no válida.');
        }

        return array_map(function (array $leg) {
            $geometry = [];
            foreach ($leg['steps'] ?? [] as $step) {
                foreach ($step['geometry']['coordinates'] ?? [] as [$lng, $lat]) {
                    $point = [round((float) $lat, 6), round((float) $lng, 6)];
                    if ($geometry === [] || end($geometry) !== $point) {
                        $geometry[] = $point;
                    }
                }
            }

            return ['geometry' => $geometry, 'meters' => (int) round($leg['distance'] ?? 0)];
        }, $legs);
    }
}
