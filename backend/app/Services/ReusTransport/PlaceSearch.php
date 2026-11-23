<?php

namespace App\Services\ReusTransport;

use App\Exceptions\ProviderException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Convierte texto («polígon Agro Reus», «Hospital Sant Joan») en coordenadas con Nominatim
 * (OpenStreetMap). Su política de uso pide como mucho una petición por segundo, un User-Agent
 * identificable y cachear resultados: por eso se consulta desde aquí y no desde el navegador.
 */
class PlaceSearch
{
    /** Rectángulos de búsqueda (oeste, norte, este, sur): Reus y alrededores, o toda Cataluña. */
    private const VIEWBOXES = ['reus' => '1.03,41.20,1.20,41.09', 'catalunya' => '0.15,42.87,3.33,40.52'];

    /** Centro de Reus: los resultados se ordenan por cercanía a él. */
    private const CENTER = [41.1556, 1.1066];

    /** @return list<array{name:string,label:string,lat:float,lng:float}> */
    public function search(string $query, string $area = 'reus'): array
    {
        $query = trim(preg_replace('/\s+/u', ' ', $query) ?? '');
        $viewbox = self::VIEWBOXES[$area] ?? self::VIEWBOXES['reus'];
        $key = "reus:places:v2:$area:".sha1(mb_strtolower($query));

        return Cache::remember($key, 86400, function () use ($query, $viewbox) {
            try {
                $results = Http::baseUrl(rtrim((string) config('services.nominatim.url'), '/'))
                    ->timeout(8)
                    ->withUserAgent('ReusBusPersonalApp/1.0 ('.config('app.url').')')
                    ->acceptJson()
                    ->get('/search', [
                        'q' => $query,
                        'format' => 'jsonv2',
                        'limit' => 6,
                        'countrycodes' => 'es',
                        'viewbox' => $viewbox,
                        'bounded' => 1,
                        'accept-language' => 'es',
                    ])
                    ->throw()
                    ->json();
            } catch (Throwable $e) {
                throw new ProviderException('No se pudo consultar el buscador de lugares.', previous: $e);
            }

            return collect(is_array($results) ? $results : [])
                ->filter(fn ($place) => isset($place['lat'], $place['lon'], $place['display_name']))
                ->map(function (array $place) {
                    $parts = array_map('trim', explode(',', (string) $place['display_name']));
                    $name = trim((string) ($place['name'] ?? '')) ?: $parts[0];

                    return [
                        'name' => $name,
                        // Nombre más las dos siguientes partes de la dirección: suficiente para distinguir.
                        'label' => implode(', ', array_slice(array_values(array_filter($parts, fn ($part) => $part !== $name)), 0, 2)),
                        'lat' => (float) $place['lat'],
                        'lng' => (float) $place['lon'],
                    ];
                })
                ->unique(fn ($place) => $place['name'].'|'.$place['label'])
                // Con nombres repetidos («Platja de Llevant» hay en Salou y en Barcelona), primero
                // los más cercanos a Reus: la app es para el Camp de Tarragona.
                ->sortBy(fn ($place) => ($place['lat'] - self::CENTER[0]) ** 2 + (($place['lng'] - self::CENTER[1]) * 0.75) ** 2)
                ->values()
                ->all();
        });
    }
}
