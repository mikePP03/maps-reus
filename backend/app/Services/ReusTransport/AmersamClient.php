<?php

namespace App\Services\ReusTransport;

use App\Exceptions\ProviderException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

class AmersamClient
{
    public function home(): string
    {
        return $this->get('/');
    }

    public function line(string $number): string
    {
        return $this->get('/lines/'.$number);
    }

    public function schedule(string $line, string $stop): string
    {
        return $this->get("/scheduler/$line/$stop");
    }

    /** @return array<string,mixed> */
    public function arrival(string $line, string $stop): array
    {
        try {
            $json = $this->request()->acceptJson()->get("/timetoarrival_real_time/$line/$stop")->throw()->json();
        } catch (Throwable $e) {
            throw new ProviderException('No se pudo consultar el proveedor.', previous: $e);
        }
        if (! is_array($json)) {
            throw new ProviderException('El proveedor devolvió JSON inválido.');
        }

        return $json;
    }

    /**
     * Consulta varias líneas de una misma parada a la vez, sin reintentos y con un timeout corto,
     * para que el tiempo total sea el de la petición más lenta y no la suma de todas.
     *
     * @param  list<string>  $lines
     * @return array<string,array<string,mixed>|null> null cuando el proveedor falló para esa línea
     */
    public function arrivalsForStop(array $lines, string $stop): array
    {
        $responses = Http::pool(fn (Pool $pool) => array_map(
            fn (string $line) => $this->configure($pool->as($line), 5)->acceptJson()->get("/timetoarrival_real_time/$line/$stop"),
            $lines,
        ));

        return collect($lines)->mapWithKeys(function (string $line) use ($responses) {
            $response = $responses[$line] ?? null;
            $json = $response instanceof Response && $response->successful() ? $response->json() : null;

            return [$line => is_array($json) ? $json : null];
        })->all();
    }

    private function get(string $path): string
    {
        try {
            return $this->request()->accept('text/html')->get($path)->throw()->body();
        } catch (Throwable $e) {
            throw new ProviderException('No se pudo consultar el proveedor.', previous: $e);
        }
    }

    private function request(): PendingRequest
    {
        return $this->configure(Http::createPendingRequest(), 10)->retry(2, 200, throw: false);
    }

    private function configure(PendingRequest $request, int $timeout): PendingRequest
    {
        return $request->baseUrl(rtrim((string) config('services.reus_transport.base_url'), '/'))->timeout($timeout)->connectTimeout(3)->withUserAgent('ReusBusPersonalApp/1.0');
    }
}
