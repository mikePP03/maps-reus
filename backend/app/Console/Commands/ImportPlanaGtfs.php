<?php

namespace App\Console\Commands;

use App\Services\Plana\PlanaGtfsImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class ImportPlanaGtfs extends Command
{
    protected $signature = 'plana:import-gtfs
        {--file= : ZIP GTFS local; si se omite, se descarga el oficial de la Generalitat}
        {--agency= : Solo operadores cuyo nombre contenga este texto (por defecto, todos)}
        {--all-areas : Importa toda Cataluña en lugar de solo la zona configurada}';

    protected $description = 'Importa las líneas, paradas y horarios del Camp de Tarragona desde el GTFS de la Generalitat';

    public function handle(PlanaGtfsImporter $importer): int
    {
        // El GTFS completo de Cataluña es grande: los trazados ocupan decenas de MB.
        ini_set('memory_limit', '1024M');
        $url = (string) config('services.plana.gtfs_url');
        $file = $this->option('file');

        if (! $file) {
            $file = storage_path('app/private/gtfs/catalunya.zip');
            @mkdir(dirname($file), 0775, true);
            $this->info("Descargando $url …");
            try {
                Http::timeout(300)->withUserAgent('ReusBusPersonalApp/1.0 ('.config('app.url').')')->sink($file)->get($url)->throw();
            } catch (Throwable $e) {
                $this->error('No se pudo descargar el GTFS: '.$e->getMessage());

                return self::FAILURE;
            }
        }

        try {
            $stats = $importer->import(
                (string) $file,
                (string) ($this->option('agency') ?: config('services.plana.agency')) ?: null,
                $this->option('all-areas') ? null : config('services.plana.area'),
                $this->option('file') ? null : $url,
            );
        } catch (Throwable $e) {
            $this->error('No se pudo importar el GTFS: '.$e->getMessage());

            return self::FAILURE;
        }
        Cache::forget('plana:map-stops');
        Cache::forget('plana:lines');

        $this->table(['Métrica', 'Valor'], collect($stats)->map(fn ($value, $key) => [$key, $value])->values()->all());

        return self::SUCCESS;
    }
}
