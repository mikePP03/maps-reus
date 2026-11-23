<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trazado por calles entre dos paradas consecutivas de alguna línea. Se calcula una vez con
 * `reus:build-geometry` y lo comparten todas las líneas que hacen ese mismo tramo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('route_segments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('from_stop_id')->constrained('stops')->cascadeOnDelete();
            $table->foreignId('to_stop_id')->constrained('stops')->cascadeOnDelete();
            // Lista de [lat, lng] en orden de marcha.
            $table->json('geometry');
            $table->unsignedInteger('meters');
            $table->timestamps();
            $table->unique(['from_stop_id', 'to_stop_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('route_segments');
    }
};
