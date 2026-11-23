<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bus_lines', function (Blueprint $table) {
            $table->id();
            $table->string('external_id')->unique();
            $table->string('public_name');
            $table->string('name')->nullable();
            $table->string('source_url')->nullable();
            $table->boolean('active')->default(true)->index();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });
        Schema::create('stops', function (Blueprint $table) {
            $table->id();
            $table->string('external_id')->unique();
            $table->string('name')->index();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('source_url')->nullable();
            $table->boolean('active')->default(true)->index();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });
        Schema::create('bus_line_stop', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bus_line_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stop_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('stop_sequence');
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->unique(['bus_line_id', 'stop_id']);
            $table->index(['bus_line_id', 'stop_sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bus_line_stop');
        Schema::dropIfExists('stops');
        Schema::dropIfExists('bus_lines');
    }
};
