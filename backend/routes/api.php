<?php

use App\Http\Controllers\Api\PlanaController;
use App\Http\Controllers\Api\ReusTransportController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/reus')->group(function () {
    Route::get('/lines', [ReusTransportController::class, 'lines']);
    Route::get('/lines/{line}', [ReusTransportController::class, 'line']);
    Route::get('/lines/{line}/stops', [ReusTransportController::class, 'lineStops']);
    Route::get('/stops/nearby', [ReusTransportController::class, 'nearby']);
    Route::get('/map-stops', [ReusTransportController::class, 'mapStops']);
    Route::get('/routes/direct', [ReusTransportController::class, 'directRoutes']);
    Route::get('/journeys', [ReusTransportController::class, 'journeys']);
    // Consulta tiempo real y horarios del proveedor por cada tramo de autobús.
    Route::get('/journeys/eta', [ReusTransportController::class, 'journeyEta'])->middleware('throttle:reus-provider');
    // Nominatim permite como mucho una petición por segundo: el límite y la caché lo protegen.
    Route::get('/places', [ReusTransportController::class, 'places'])->middleware('throttle:reus-places');
    Route::get('/walk-path', [ReusTransportController::class, 'walkPath'])->middleware('throttle:reus-walk');
    Route::get('/stops', [ReusTransportController::class, 'stops']);
    Route::get('/stops/{stop}', [ReusTransportController::class, 'stop']);
    Route::get('/stops/{stop}/lines', [ReusTransportController::class, 'stopLines']);
    Route::get('/stops/{stop}/connections', [ReusTransportController::class, 'stopConnections']);
    // Cada consulta de una parada completa puede generar una petición al proveedor por línea.
    Route::get('/stops/{stop}/arrivals', [ReusTransportController::class, 'stopArrivals'])->middleware('throttle:reus-stop-arrivals');
    Route::middleware('throttle:reus-provider')->group(function () {
        Route::get('/arrivals', [ReusTransportController::class, 'arrival']);
        Route::get('/schedules', [ReusTransportController::class, 'schedule']);
    });
});

// Red interurbana de Plana (GTFS de la Generalitat): solo horarios, sin tiempo real.
Route::prefix('v1/plana')->group(function () {
    Route::get('/feed', [PlanaController::class, 'feed']);
    Route::get('/lines', [PlanaController::class, 'lines']);
    Route::get('/lines/{line}', [PlanaController::class, 'line']);
    Route::get('/lines/{line}/timetable', [PlanaController::class, 'timetable']);
    Route::get('/map-stops', [PlanaController::class, 'mapStops']);
    Route::get('/stops', [PlanaController::class, 'stops']);
    Route::get('/stops/{stop}/departures', [PlanaController::class, 'departures']);
    Route::get('/journeys', [PlanaController::class, 'journeys'])->middleware('throttle:reus-provider');
    Route::get('/journeys/day', [PlanaController::class, 'dayJourneys'])->middleware('throttle:reus-provider');
});
