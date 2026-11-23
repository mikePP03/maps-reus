<?php

namespace App\Services\ReusTransport;

use App\Exceptions\ProviderException;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Horas reales de un viaje: combina el tiempo real del proveedor (que solo da el próximo bus de
 * cada línea en cada parada) con su horario (para los siguientes). Así se sabe si se llega a coger
 * el primer autobús y, sobre todo, si da tiempo al transbordo o hay que esperar al siguiente.
 */
class JourneyEta
{
    /** Margen para considerar que aún da tiempo a coger un autobús que llega justo. */
    private const TOLERANCE_SECONDS = 30;

    public function __construct(private ArrivalService $arrivals) {}

    /**
     * @param  list<array{line:string,stop:string,ride:int}>  $legs  tramos de autobús en orden
     * @return array<string,mixed>
     */
    public function calculate(array $legs, int $walkBefore, int $walkAfter, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now($this->timezone());
        $ready = $now->addMinutes($walkBefore);
        $result = [];
        $complete = true;

        foreach ($legs as $leg) {
            if (! $complete) {
                $result[] = ['line' => $leg['line'], 'stop' => $leg['stop'], 'source' => null];

                continue;
            }
            $departure = $this->departure($leg['line'], $leg['stop'], $now, $ready);
            if ($departure['at'] === null) {
                $complete = false;
                $result[] = ['line' => $leg['line'], 'stop' => $leg['stop'], 'source' => null, 'ready_at' => $this->time($ready), 'missed_at' => $departure['missed']];

                continue;
            }
            $arrives = $departure['at']->addMinutes($leg['ride']);
            $result[] = [
                'line' => $leg['line'],
                'stop' => $leg['stop'],
                'source' => $departure['source'],
                'ready_at' => $this->time($ready),
                'departs_at' => $this->time($departure['at']),
                // Hoy ya no pasan más: el siguiente autobús es mañana.
                'next_day' => ! $departure['at']->isSameDay($now),
                'departs_in' => max(0, (int) round($now->diffInSeconds($departure['at'], false) / 60)),
                'wait_minutes' => max(0, (int) round($ready->diffInSeconds($departure['at'], false) / 60)),
                // Bus que pasa antes de que llegues a la parada (lo pierdes).
                'missed_at' => $departure['missed'],
                'arrives_at' => $this->time($arrives),
            ];
            $ready = $arrives;
        }

        $arrival = $complete ? $ready->addMinutes($walkAfter) : null;

        return [
            'complete' => $complete,
            'now' => $this->time($now),
            'arrives_at' => $arrival ? $this->time($arrival) : null,
            'arrives_next_day' => $arrival !== null && ! $arrival->isSameDay($now),
            'total_minutes' => $arrival ? (int) round($now->diffInSeconds($arrival) / 60) : null,
            'legs' => $result,
        ];
    }

    /**
     * Primer autobús de la línea que pasa por la parada a partir de $ready.
     *
     * @return array{at:?CarbonImmutable,source:?string,missed:?string}
     */
    private function departure(string $line, string $stop, CarbonImmutable $now, CarbonImmutable $ready): array
    {
        $missed = null;
        $minutes = $this->realtimeMinutes($line, $stop);
        if ($minutes !== null) {
            $realtime = $now->addMinutes($minutes);
            if ($realtime->greaterThanOrEqualTo($ready->subSeconds(self::TOLERANCE_SECONDS))) {
                return ['at' => $realtime, 'source' => 'realtime', 'missed' => null];
            }
            $missed = $this->time($realtime);
        }

        foreach ($this->scheduledTimes($line, $stop, $now) as $time) {
            if ($time->greaterThanOrEqualTo($ready->subSeconds(self::TOLERANCE_SECONDS))
                && ($missed === null || $time->greaterThan($now->addMinutes((int) $minutes)))) {
                return ['at' => $time, 'source' => 'schedule', 'missed' => $missed];
            }
        }

        return ['at' => null, 'source' => null, 'missed' => $missed];
    }

    /** Minutos del próximo bus según el tiempo real, o null si no hay previsión. */
    private function realtimeMinutes(string $line, string $stop): ?int
    {
        try {
            $value = $this->arrivals->getArrival($line, $stop)['timetoarrival'] ?? null;
        } catch (ProviderException) {
            return null;
        }

        return is_string($value) && preg_match('/^\s*(\d+)/', $value, $match) ? (int) $match[1] : null;
    }

    /**
     * Horas de paso de hoy (y de mañana temprano, por si el viaje cruza la medianoche), ordenadas.
     *
     * @return list<CarbonImmutable>
     */
    private function scheduledTimes(string $line, string $stop, CarbonImmutable $now): array
    {
        try {
            $sections = $this->arrivals->schedule($line, $stop)['sections'] ?? [];
        } catch (Throwable) {
            return [];
        }
        $times = [];
        foreach ($sections as $section) {
            foreach ($section['times'] ?? [] as $time) {
                [$hour, $minute] = array_map('intval', explode(':', $time));
                $today = $now->setTime($hour, $minute);
                $times[] = $today;
                $times[] = $today->addDay();
            }
        }
        usort($times, fn ($a, $b) => $a <=> $b);

        return $times;
    }

    private function time(CarbonImmutable $moment): string
    {
        return $moment->format('H:i');
    }

    private function timezone(): string
    {
        return (string) config('services.reus_transport.timezone', 'Europe/Madrid');
    }
}
