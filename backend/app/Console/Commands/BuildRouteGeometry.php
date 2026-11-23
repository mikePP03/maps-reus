<?php

namespace App\Console\Commands;

use App\Exceptions\ProviderException;
use App\Models\BusLine;
use App\Models\RouteSegment;
use App\Models\Stop;
use App\Services\ReusTransport\StreetRouter;
use Illuminate\Console\Command;

class BuildRouteGeometry extends Command
{
    protected $signature = 'reus:build-geometry {--line= : Solo esta línea (ej. 60)} {--force : Recalcula también los tramos ya guardados}';

    protected $description = 'Calcula el trazado por calles entre paradas consecutivas de cada línea';

    public function handle(StreetRouter $router): int
    {
        $lines = BusLine::where('active', true)
            ->when($this->option('line'), fn ($query, $line) => $query->where('external_id', 'L'.ltrim(strtoupper((string) $line), 'L')))
            ->with(['stops' => fn ($query) => $query->where('stops.active', true)])
            ->get()
            ->sortBy('public_name', SORT_NATURAL);
        $stats = ['lines' => 0, 'requested' => 0, 'stored' => 0, 'discarded' => 0, 'skipped' => 0];
        $errors = [];

        foreach ($lines as $line) {
            $stats['lines']++;
            foreach ($this->runs($line->stops->values()->all()) as $run) {
                if (! $this->option('force') && $this->alreadyStored($run)) {
                    $stats['skipped'] += count($run) - 1;

                    continue;
                }
                try {
                    $legs = $router->legs('car', array_map(fn (Stop $stop) => [(float) $stop->latitude, (float) $stop->longitude], $run));
                    $stats['requested']++;
                } catch (ProviderException $e) {
                    $errors[] = "L{$line->public_name}: {$e->getMessage()}";

                    continue;
                }
                foreach ($legs as $index => $leg) {
                    [$from, $to] = [$run[$index], $run[$index + 1]];
                    $straight = $this->straightMeters($from, $to);
                    // Un rodeo exagerado suele ser una parada asociada a la vía equivocada (una
                    // carretera paralela): mejor una línea recta que dibujar una vuelta falsa.
                    $plausible = $leg['meters'] <= $straight * 3 || $leg['meters'] - $straight <= 300;
                    if (! $plausible) {
                        $stats['discarded']++;
                    }
                    RouteSegment::updateOrCreate(
                        ['from_stop_id' => $from->id, 'to_stop_id' => $to->id],
                        $plausible
                            ? ['geometry' => $leg['geometry'], 'meters' => $leg['meters']]
                            : ['geometry' => [[(float) $from->latitude, (float) $from->longitude], [(float) $to->latitude, (float) $to->longitude]], 'meters' => $straight],
                    );
                    $stats['stored']++;
                }
                // El servidor público pide como mucho una petición por segundo.
                sleep(1);
            }
        }

        $this->table(['Métrica', 'Valor'], collect($stats)->map(fn ($value, $key) => [$key, $value])->values()->all());
        foreach ($errors as $error) {
            $this->warn($error);
        }

        return $errors === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Tramos seguidos de paradas con coordenadas; una parada sin ellas corta el recorrido.
     *
     * @param  list<Stop>  $stops
     * @return list<list<Stop>>
     */
    private function runs(array $stops): array
    {
        $runs = [[]];
        foreach ($stops as $stop) {
            if ($stop->latitude === null || $stop->longitude === null) {
                $runs[] = [];

                continue;
            }
            $runs[array_key_last($runs)][] = $stop;
        }

        return array_values(array_filter($runs, fn ($run) => count($run) > 1));
    }

    private function straightMeters(Stop $a, Stop $b): int
    {
        $dLat = deg2rad((float) $b->latitude - (float) $a->latitude);
        $dLng = deg2rad((float) $b->longitude - (float) $a->longitude);
        $h = sin($dLat / 2) ** 2 + cos(deg2rad((float) $a->latitude)) * cos(deg2rad((float) $b->latitude)) * sin($dLng / 2) ** 2;

        return (int) round(6371000 * 2 * asin(min(1, sqrt($h))));
    }

    /** @param list<Stop> $run */
    private function alreadyStored(array $run): bool
    {
        for ($i = 0; $i < count($run) - 1; $i++) {
            if (! RouteSegment::where('from_stop_id', $run[$i]->id)->where('to_stop_id', $run[$i + 1]->id)->exists()) {
                return false;
            }
        }

        return true;
    }
}
