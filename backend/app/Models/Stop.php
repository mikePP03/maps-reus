<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Stop extends Model
{
    protected $fillable = ['external_id', 'name', 'latitude', 'longitude', 'source_url', 'active', 'last_synced_at'];

    protected $hidden = ['source_url', 'created_at', 'updated_at'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'latitude' => 'decimal:7', 'longitude' => 'decimal:7', 'last_synced_at' => 'datetime'];
    }

    /** Líneas distintas que pasan por la parada; una circular aparece una sola vez aunque la visite dos. */
    public function busLines(): BelongsToMany
    {
        return $this->belongsToMany(BusLine::class)->distinct();
    }
}
