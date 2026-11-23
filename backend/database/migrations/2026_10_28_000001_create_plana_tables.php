<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Red interurbana de Plana a partir del GTFS oficial de la Generalitat. Va en tablas propias,
 * separada de la red urbana de Reus: `plana:import-gtfs` las vacía y las vuelve a llenar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plana_routes', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('agency_name');
            $table->string('short_name');
            $table->string('long_name');
            $table->string('color', 6)->nullable();
            $table->string('text_color', 6)->nullable();
        });
        Schema::create('plana_stops', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('code')->nullable();
            $table->string('name')->index();
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
        });
        Schema::create('plana_trips', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('route_id')->index();
            $table->string('service_id')->index();
            $table->string('headsign')->nullable();
            $table->unsignedTinyInteger('direction_id')->nullable();
            $table->string('shape_id')->nullable();
            // Aviso de la Generalitat (nota.txt): servicio a demanda, recorridos alternativos…
            $table->text('note')->nullable();
        });
        Schema::create('plana_stop_times', function (Blueprint $table) {
            $table->id();
            $table->string('trip_id');
            $table->string('stop_id');
            $table->unsignedInteger('sequence');
            // Segundos desde la medianoche del día de servicio; pueden pasar de 24 h.
            $table->unsignedInteger('arrival');
            $table->unsignedInteger('departure');
            $table->index(['trip_id', 'sequence']);
            $table->index(['stop_id', 'departure']);
        });
        Schema::create('plana_service_dates', function (Blueprint $table) {
            $table->string('service_id');
            $table->date('date')->index();
            $table->primary(['service_id', 'date']);
        });
        Schema::create('plana_shapes', function (Blueprint $table) {
            $table->string('id')->primary();
            // Lista de [lat, lng] en orden.
            $table->json('points');
        });
        // Transbordos a pie entre paradas cercanas (calculados al importar).
        Schema::create('plana_footpaths', function (Blueprint $table) {
            $table->string('from_stop_id');
            $table->string('to_stop_id');
            $table->unsignedInteger('meters');
            $table->primary(['from_stop_id', 'to_stop_id']);
        });
        Schema::create('plana_feed', function (Blueprint $table) {
            $table->id();
            $table->timestamp('imported_at');
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->string('source_url')->nullable();
            $table->json('stats');
        });
    }

    public function down(): void
    {
        foreach (['plana_feed', 'plana_footpaths', 'plana_shapes', 'plana_service_dates', 'plana_stop_times', 'plana_trips', 'plana_stops', 'plana_routes'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
