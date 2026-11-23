<?php

namespace Tests\Feature;

use App\Models\BusLine;
use App\Models\Stop;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class JourneyEtaTest extends TestCase
{
    use RefreshDatabase;

    /** L60: 93 → 198 y L31: 198 → 5. Son las 10:00 en Reus. */
    protected function setUp(): void
    {
        parent::setUp();
        cache()->flush();
        $this->travelTo(CarbonImmutable::parse('2026-10-27 10:00', 'Europe/Madrid'));
        foreach (['L60' => ['93', '198'], 'L31' => ['198', '5']] as $id => $codes) {
            $line = BusLine::create(['external_id' => $id, 'public_name' => substr($id, 1), 'active' => true]);
            foreach ($codes as $index => $code) {
                $stop = Stop::firstOrCreate(['external_id' => $code], ['name' => "Parada $code", 'active' => true]);
                $line->stops()->attach($stop, ['stop_sequence' => $index + 1]);
            }
        }
    }

    /** @param array<string,mixed> $realtime minutos por "línea/parada" */
    private function fakeProvider(array $realtime, string $l31Schedule = '<p>09:50</p><p>10:50</p><p>11:50</p>'): void
    {
        Http::fake(function ($request) use ($realtime, $l31Schedule) {
            foreach ($realtime as $key => $minutes) {
                if (str_ends_with($request->url(), "/timetoarrival_real_time/$key")) {
                    return Http::response(['timetoarrival' => $minutes]);
                }
            }

            return str_contains($request->url(), '/scheduler/L31/198')
                ? Http::response("<main>$l31Schedule</main>")
                : Http::response('<main>Sense servei</main>');
        });
    }

    /** 3 min a pie, L60 desde la 93 (12 min de trayecto), L31 desde la 198 (8 min), 2 min a pie. */
    private function eta(): TestResponse
    {
        return $this->getJson('/api/v1/reus/journeys/eta?'.http_build_query([
            'legs' => [['line' => 'L60', 'stop' => '93', 'ride' => 12], ['line' => 'L31', 'stop' => '198', 'ride' => 8]],
            'walk_before' => 3,
            'walk_after' => 2,
        ]));
    }

    public function test_catches_the_transfer_using_real_time_for_both_buses(): void
    {
        // L60 pasa en 5 min (10:05) → llegas a la 198 a las 10:17; la L31 pasa a las 10:20.
        $this->fakeProvider(['L60/93' => 5, 'L31/198' => 20]);

        $this->eta()->assertOk()
            ->assertJsonPath('data.complete', true)
            ->assertJsonPath('data.legs.0.source', 'realtime')
            ->assertJsonPath('data.legs.0.departs_at', '10:05')
            ->assertJsonPath('data.legs.0.wait_minutes', 2)
            ->assertJsonPath('data.legs.1.ready_at', '10:17')
            ->assertJsonPath('data.legs.1.departs_at', '10:20')
            ->assertJsonPath('data.legs.1.wait_minutes', 3)
            ->assertJsonPath('data.legs.1.missed_at', null)
            ->assertJsonPath('data.arrives_at', '10:30')
            ->assertJsonPath('data.total_minutes', 30);
    }

    public function test_missing_the_transfer_falls_back_to_the_next_scheduled_bus(): void
    {
        // La L31 pasa a las 10:10, antes de que llegues (10:17): siguiente según horario, 10:50.
        $this->fakeProvider(['L60/93' => 5, 'L31/198' => 10]);

        $this->eta()->assertOk()
            ->assertJsonPath('data.legs.1.source', 'schedule')
            ->assertJsonPath('data.legs.1.missed_at', '10:10')
            ->assertJsonPath('data.legs.1.departs_at', '10:50')
            ->assertJsonPath('data.legs.1.wait_minutes', 33)
            ->assertJsonPath('data.arrives_at', '11:00');
    }

    public function test_cannot_reach_the_first_stop_in_time(): void
    {
        // La L60 pasa en 1 min y necesitas 3 para llegar a la parada: horario de la L60 vacío.
        $this->fakeProvider(['L60/93' => 1, 'L31/198' => 20]);

        $this->eta()->assertOk()
            ->assertJsonPath('data.complete', false)
            ->assertJsonPath('data.legs.0.source', null)
            ->assertJsonPath('data.legs.0.missed_at', '10:01')
            ->assertJsonPath('data.arrives_at', null);
    }

    public function test_without_real_time_uses_the_schedule_and_reports_next_day(): void
    {
        // Sin previsión para la L31 y su último paso de hoy ya ha salido: el siguiente es mañana.
        $this->fakeProvider(['L60/93' => 5, 'L31/198' => 'CONSULTEU HORARI'], '<p>06:15</p><p>09:50</p>');

        $this->eta()->assertOk()
            ->assertJsonPath('data.legs.1.source', 'schedule')
            ->assertJsonPath('data.legs.1.departs_at', '06:15')
            ->assertJsonPath('data.legs.1.next_day', true)
            ->assertJsonPath('data.arrives_next_day', true);
    }

    public function test_rejects_combinations_that_do_not_exist(): void
    {
        Http::fake();
        $this->getJson('/api/v1/reus/journeys/eta?'.http_build_query(['legs' => [['line' => 'L31', 'stop' => '93', 'ride' => 5]]]))
            ->assertUnprocessable();
        Http::assertNothingSent();
    }
}
