<?php

namespace App\Console\Commands;

use App\Services\ReusTransport\NetworkSynchronizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class SyncReusNetwork extends Command
{
    protected $signature = 'reus:sync-network {--line=} {--dry-run} {--force} {--no-prune : No desactiva líneas ni paradas que hayan desaparecido}';

    protected $description = 'Descubre y sincroniza la red de Reus Transport';

    public function handle(NetworkSynchronizer $synchronizer): int
    {
        $lock = Cache::lock('reus:sync-network', 3600);
        if ($this->option('force')) {
            $lock->forceRelease();
        }
        if (! $lock->get()) {
            $this->error('Ya hay una sincronización en curso.');

            return self::FAILURE;
        }
        try {
            $stats = $synchronizer->sync($this->option('line'), (bool) $this->option('dry-run'), ! $this->option('no-prune'));
            if (! $this->option('dry-run')) {
                Cache::forget('reus:lines');
                Cache::forget('reus:map-stops');
            }
            $this->table(['Métrica', 'Valor'], collect($stats)->except('errors')->map(fn ($v, $k) => [$k, $v])->values()->all());
            foreach ($stats['errors'] as $error) {
                $this->warn($error);
            }

            return $stats['errors'] === [] ? self::SUCCESS : self::FAILURE;
        } finally {
            $lock->release();
        }
    }
}
