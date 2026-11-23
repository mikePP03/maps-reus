<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Una línea puede pasar más de una vez por la misma parada (las circulares L10/L11
 * empiezan y terminan en la 13), así que la posición en el recorrido identifica la fila.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bus_line_stop', function (Blueprint $table) {
            $table->unique(['bus_line_id', 'stop_sequence']);
            $table->index('stop_id');
        });
        Schema::table('bus_line_stop', function (Blueprint $table) {
            $table->dropUnique(['bus_line_id', 'stop_id']);
            $table->dropIndex(['bus_line_id', 'stop_sequence']);
        });
    }

    public function down(): void
    {
        Schema::table('bus_line_stop', function (Blueprint $table) {
            $table->index(['bus_line_id', 'stop_sequence']);
            $table->unique(['bus_line_id', 'stop_id']);
        });
        Schema::table('bus_line_stop', function (Blueprint $table) {
            $table->dropUnique(['bus_line_id', 'stop_sequence']);
            $table->dropIndex(['stop_id']);
        });
    }
};
