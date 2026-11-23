<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('reus:sync-network')->dailyAt(config('services.reus_transport.sync_time'))->withoutOverlapping();
Schedule::command('reus:build-geometry')->dailyAt('04:30')->withoutOverlapping();
Schedule::command('plana:import-gtfs')->monthlyOn(20, '05:00')->withoutOverlapping();
