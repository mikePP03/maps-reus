<?php

namespace App\Console\Commands;

use App\Services\ReusTransport\GtfsStopImporter;
use Illuminate\Console\Command;
use PharData;
use Throwable;

class ImportReusGtfs extends Command
{
    protected $signature = 'reus:import-gtfs {--file= : Ruta al ZIP GTFS} {--dry-run : Analiza sin actualizar la base de datos}';

    protected $description = 'Completa las coordenadas de las paradas desde un archivo GTFS';

    public function handle(GtfsStopImporter $importer): int
    {
        $file = $this->option('file');
        if (! is_string($file) || $file === '' || ! is_file($file)) {
            $this->error('Indica un ZIP GTFS existente mediante --file="ruta".');

            return self::INVALID;
        }

        try {
            $archive = new PharData($file);
            if (! isset($archive['stops.txt'])) {
                $this->error('El ZIP no contiene stops.txt.');

                return self::FAILURE;
            }
            $result = $importer->import($archive['stops.txt']->getContent(), (bool) $this->option('dry-run'));
        } catch (Throwable $exception) {
            $this->error('No se pudo importar el GTFS: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->table(['Métrica', 'Valor'], [
            ['Filas GTFS válidas', $result['rows']],
            ['Coincidencias exactas', $result['matched']],
            [$this->option('dry-run') ? 'Actualizaría' : 'Actualizadas', $result['updated']],
            ['Sin cambios', $result['unchanged']],
            ['GTFS sin parada local', count($result['not_in_local'])],
            ['Paradas locales sin GTFS', count($result['local_without_gtfs'])],
        ]);
        if ($result['local_without_gtfs'] !== []) {
            $this->warn('Sin coordenadas GTFS: '.implode(', ', $result['local_without_gtfs']));
        }
        if ($result['not_in_local'] !== []) {
            $this->line('Códigos GTFS fuera de la red local: '.implode(', ', $result['not_in_local']));
        }

        return self::SUCCESS;
    }
}
