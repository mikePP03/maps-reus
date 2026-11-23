<?php

namespace Tests\Feature;

use App\Services\Plana\PlanaGtfsImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PharData;
use Tests\TestCase;

class PlanaTest extends TestCase
{
    use RefreshDatabase;

    private string $zip;

    /**
     * Red mínima: la línea «(e5) Reus - Salou» va de R1 a S1 y la L0700 de S1 a C1 (transbordo en S1).
     * Otra empresa (no Plana) tiene una línea directa R1 → C1 que no debe importarse.
     * El 2026-10-28 (miércoles) circulan; el 2026-10-31 no (sin servicio).
     */
    protected function setUp(): void
    {
        parent::setUp();
        cache()->flush();
        // El horario pone nombre a cada sentido con las coordenadas de las poblaciones: sin internet.
        $towns = ['Reus' => ['41.1550', '1.1070'], 'Salou' => ['41.0770', '1.1440']];
        Http::fake(['*/search*' => fn ($request) => Http::response(
            isset($towns[$request['q']]) ? [['name' => $request['q'], 'display_name' => $request['q'], 'lat' => $towns[$request['q']][0], 'lon' => $towns[$request['q']][1]]] : [],
        )]);
        $files = [
            'agency.txt' => "agency_id,agency_name,agency_url,agency_timezone\n1,Empresa Plana SL,https://x,Europe/Madrid\n2,Otra Empresa,https://y,Europe/Madrid\n",
            'routes.txt' => "route_id,agency_id,route_short_name,route_long_name,route_type\nR5,1,L1726,(e5) Reus - Salou,3\nR7,1,L0700,Salou - Cambrils,3\nRX,2,L9999,Reus - Cambrils directe,3\nRG,2,L8888,Girona - Salt,3\n",
            'trips.txt' => "route_id,service_id,trip_id,trip_headsign,direction_id,shape_id\nR5,LAB,T1,Salou,0,SH1\nR5,LAB,T2,Salou,0,SH1\nR7,LAB,T3,Cambrils,0,\nRX,LAB,TX,Cambrils,0,\nRG,LAB,TG,Salt,0,\n",
            'stops.txt' => "stop_id,stop_code,stop_name,stop_lat,stop_lon\nR1,,Reus (estació d'autobusos),41.1500,1.1000\nR2,,Reus sud,41.1400,1.1050\nS1,,Salou centre,41.0800,1.1300\nS2,,Salou centre (davant),41.0801,1.1302\nC1,,Cambrils port,41.0670,1.0600\nG1,,Girona estació,41.9790,2.8170\nG2,,Salt centre,41.9750,2.7900\n",
            'stop_times.txt' => "trip_id,arrival_time,departure_time,stop_id,stop_sequence\n"
                ."T1,08:00:00,08:00:00,R1,1\nT1,08:10:00,08:10:00,R2,2\nT1,08:30:00,08:30:00,S1,3\n"
                ."T2,09:00:00,09:00:00,R1,1\nT2,09:10:00,09:10:00,R2,2\nT2,09:30:00,09:30:00,S1,3\n"
                ."T3,08:40:00,08:40:00,S2,1\nT3,09:00:00,09:00:00,C1,2\n"
                ."TX,08:05:00,08:05:00,R1,1\nTX,08:20:00,08:20:00,C1,2\n"
                ."TG,10:00:00,10:00:00,G1,1\nTG,10:15:00,10:15:00,G2,2\n",
            'calendar.txt' => "service_id,monday,tuesday,wednesday,thursday,friday,saturday,sunday,start_date,end_date\nLAB,0,0,0,0,0,0,0,20260101,20261231\n",
            'calendar_dates.txt' => "service_id,date,exception_type\nLAB,20261028,1\n",
            'shapes.txt' => "shape_id,shape_pt_lat,shape_pt_lon,shape_pt_sequence\nSH1,41.1500,1.1000,1\nSH1,41.1450,1.1020,2\nSH1,41.1400,1.1050,3\nSH1,41.1100,1.1200,4\nSH1,41.0800,1.1300,5\n",
            'nota.txt' => "route_id,trip_id,nota\nR7,T3,\"Servei a la demanda. Reserva prèvia\"\n",
        ];
        $this->zip = sys_get_temp_dir().'/plana-test-'.uniqid().'.zip';
        $archive = new PharData($this->zip);
        foreach ($files as $name => $content) {
            $archive->addFromString($name, $content);
        }
        app(PlanaGtfsImporter::class)->import($this->zip, 'Plana');
    }

    protected function tearDown(): void
    {
        @unlink($this->zip);
        parent::tearDown();
    }

    public function test_imports_only_the_matching_operator(): void
    {
        $this->getJson('/api/v1/plana/feed')->assertOk()
            ->assertJsonPath('data.stats.routes', 2)
            ->assertJsonPath('data.stats.trips', 3)
            ->assertJsonPath('data.valid_from', '2026-10-28');
        $this->getJson('/api/v1/plana/lines')->assertOk()
            ->assertJsonPath('data.0.name', 'e5')
            ->assertJsonPath('data.0.description', 'Reus - Salou')
            ->assertJsonCount(2, 'data');
    }

    public function test_departures_follow_the_calendar_and_skip_the_last_stop(): void
    {
        $this->getJson('/api/v1/plana/stops/R1/departures?date=2026-10-28&time=08:30')->assertOk()
            ->assertJsonPath('data.0.time', '09:00')
            ->assertJsonPath('data.0.line.name', 'e5')
            ->assertJsonPath('data.0.in_minutes', 30);
        // En la última parada de la expedición no se puede subir.
        $this->getJson('/api/v1/plana/stops/C1/departures?date=2026-10-28&time=07:00')->assertOk()->assertJsonCount(0, 'data');
        // Un día sin servicio no hay salidas.
        $this->getJson('/api/v1/plana/stops/R1/departures?date=2026-10-31&time=07:00')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_direct_journey_with_official_shape(): void
    {
        $journey = $this->getJson('/api/v1/plana/journeys?from_stop=R1&to_stop=S1&date=2026-10-28&time=07:50')
            ->assertOk()
            ->assertJsonPath('data.0.lines', ['e5'])
            ->assertJsonPath('data.0.departs_at', '08:00')
            ->assertJsonPath('data.0.arrives_at', '08:30')
            ->json('data.0');
        $this->assertGreaterThanOrEqual(5, count($journey['legs'][0]['geometry']));
    }

    public function test_transfer_with_a_short_walk_between_nearby_stops(): void
    {
        $legs = $this->getJson('/api/v1/plana/journeys?from_stop=R1&to_stop=C1&date=2026-10-28&time=07:50')
            ->assertOk()
            ->assertJsonPath('data.0.lines', ['e5', 'L0700'])
            ->assertJsonPath('data.0.transfers', 1)
            ->assertJsonPath('data.0.arrives_at', '09:00')
            ->json('data.0.legs');

        $this->assertSame(['bus', 'walk', 'bus'], array_column($legs, 'type'));
        $this->assertSame('S2', $legs[1]['to']['code']);
        $this->assertSame('Servei a la demanda. Reserva prèvia', $legs[2]['note']);
    }

    public function test_no_journey_when_the_service_does_not_run(): void
    {
        $this->getJson('/api/v1/plana/journeys?from_stop=R1&to_stop=C1&date=2026-10-31&time=07:50')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_area_import_takes_every_operator_with_stops_in_the_zone(): void
    {
        // Camp de Tarragona, sin filtro de operador: entra la línea de «Otra Empresa» de Reus,
        // pero no la de Girona.
        app(PlanaGtfsImporter::class)->import($this->zip, null, [40.95, 41.50, 0.65, 1.45]);

        $this->getJson('/api/v1/plana/feed')->assertOk()->assertJsonPath('data.stats.routes', 3);
        $names = array_column($this->getJson('/api/v1/plana/lines')->json('data'), 'name');
        $this->assertContains('L9999', $names);
        $this->assertNotContains('L8888', $names);
        // Con la línea directa de otro operador, la ruta más rápida a Cambrils ya no tiene transbordo.
        $this->getJson('/api/v1/plana/journeys?from_stop=R1&to_stop=C1&date=2026-10-28&time=07:50')
            ->assertOk()
            ->assertJsonPath('data.0.lines', ['L9999'])
            ->assertJsonPath('data.0.arrives_at', '08:20');
    }

    public function test_line_timetable_lists_every_trip_of_the_day_in_order(): void
    {
        $this->getJson('/api/v1/plana/lines/R5/timetable?date=2026-10-28')->assertOk()
            ->assertJsonPath('data.name', 'e5')
            ->assertJsonPath('data.date', '2026-10-28')
            ->assertJsonPath('data.directions.0.headsign', 'Salou')
            ->assertJsonPath('data.directions.0.label', 'Reus → Salou')
            ->assertJsonPath('data.directions.0.stops.0.name', "Reus (estació d'autobusos)")
            ->assertJsonCount(3, 'data.directions.0.stops')
            ->assertJsonPath('data.directions.0.trips.0.times', ['08:00', '08:10', '08:30'])
            ->assertJsonPath('data.directions.0.trips.1.times', ['09:00', '09:10', '09:30']);
        // Un día sin servicio, la línea no tiene expediciones.
        $this->getJson('/api/v1/plana/lines/R5/timetable?date=2026-10-31')->assertOk()->assertJsonCount(0, 'data.directions');
        $this->getJson('/api/v1/plana/lines/NOEXISTE/timetable')->assertNotFound();
    }

    public function test_without_buses_at_that_time_it_offers_the_next_available_day(): void
    {
        // El 27 no circula nada: el siguiente disponible es el 28 a las 08:00.
        $this->getJson('/api/v1/plana/journeys?from_stop=R1&to_stop=S1&date=2026-10-27&time=20:00')
            ->assertOk()
            ->assertJsonPath('meta.next_available', true)
            ->assertJsonPath('meta.requested_date', '2026-10-27')
            ->assertJsonPath('meta.date', '2026-10-28')
            ->assertJsonPath('data.0.departs_at', '08:00');

        // Más tarde el mismo día (fuera de la ventana de 8 h) también cuenta como «siguiente».
        $this->getJson('/api/v1/plana/journeys?from_stop=R1&to_stop=S1&date=2026-10-28&time=00:30')
            ->assertOk()
            ->assertJsonPath('meta.date', '2026-10-28')
            ->assertJsonPath('data.0.departs_at', '08:00');

        // Las salidas de la parada y el horario de la línea también apuntan al día siguiente.
        $this->getJson('/api/v1/plana/stops/R1/departures?date=2026-10-27&time=20:00')->assertOk()
            ->assertJsonPath('data.0.time', '08:00')
            ->assertJsonPath('data.0.day_offset', 1)
            ->assertJsonPath('data.0.date', '2026-10-28');
        $this->getJson('/api/v1/plana/lines/R5/timetable?date=2026-10-25')->assertOk()
            ->assertJsonCount(0, 'data.directions')
            ->assertJsonPath('data.next_service_date', '2026-10-28');
    }

    public function test_day_list_gives_every_departure_of_the_trip(): void
    {
        $this->getJson('/api/v1/plana/journeys/day?from_stop=R1&to_stop=S1&date=2026-10-28&time=07:00')
            ->assertOk()
            ->assertJsonPath('meta.count', 2)
            ->assertJsonPath('meta.next_available', false)
            ->assertJsonPath('data.0.departs_at', '08:00')
            ->assertJsonPath('data.0.arrives_at', '08:30')
            ->assertJsonPath('data.1.departs_at', '09:00')
            ->assertJsonPath('data.1.lines', ['e5']);

        // Después de la última salida del día, la lista es la del próximo día con servicio.
        $this->getJson('/api/v1/plana/journeys/day?from_stop=R1&to_stop=S1&date=2026-10-27&time=12:00')
            ->assertOk()
            ->assertJsonPath('meta.date', '2026-10-28')
            ->assertJsonPath('meta.next_available', true)
            ->assertJsonPath('meta.count', 2);
    }

    public function test_points_without_a_nearby_stop_are_reported_out_of_the_network(): void
    {
        // Un destino a ~20 km de cualquier parada: nada de proponer una caminata enorme.
        foreach (['/api/v1/plana/journeys', '/api/v1/plana/journeys/day'] as $url) {
            $this->getJson("$url?from_stop=R1&to_lat=41.30&to_lng=1.25&date=2026-10-28&time=07:00")
                ->assertOk()
                ->assertJsonCount(0, 'data')
                ->assertJsonPath('meta.out_of_area.destination', true)
                ->assertJsonPath('meta.out_of_area.origin', false);
        }
    }
}
