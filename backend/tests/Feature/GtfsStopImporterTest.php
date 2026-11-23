<?php

namespace Tests\Feature;

use App\Models\Stop;
use App\Services\ReusTransport\GtfsStopImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GtfsStopImporterTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_updates_coordinates_only_for_exact_stop_ids(): void
    {
        $matched = Stop::create(['external_id' => '93', 'name' => 'Oques 3']);
        Stop::create(['external_id' => '999', 'name' => 'Sense GTFS']);
        $csv = "stop_id,stop_code,stop_name,stop_lat,stop_lon\n93,93,Nom GTFS,41.1539,1.1047\n123,123,Raval,41.15,1.11\n";

        $result = app(GtfsStopImporter::class)->import($csv);

        $this->assertSame(1, $result['matched']);
        $this->assertSame(['123'], $result['not_in_local']);
        $this->assertSame('Oques 3', $matched->fresh()->name);
        $this->assertSame('41.1539000', $matched->fresh()->latitude);
        $this->assertSame('1.1047000', $matched->fresh()->longitude);
    }

    public function test_dry_run_does_not_modify_coordinates(): void
    {
        $stop = Stop::create(['external_id' => '93', 'name' => 'Oques 3']);
        $csv = "stop_id,stop_lat,stop_lon\n93,41.1539,1.1047\n";

        $result = app(GtfsStopImporter::class)->import($csv, true);

        $this->assertSame(1, $result['updated']);
        $this->assertNull($stop->fresh()->latitude);
    }
}
