<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class BusLine extends Model
{
    protected $fillable = ['external_id', 'public_name', 'name', 'source_url', 'active', 'last_synced_at'];

    protected $hidden = ['source_url', 'created_at', 'updated_at'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'last_synced_at' => 'datetime'];
    }

    /** Recorrido ordenado; una parada puede repetirse si la línea pasa por ella más de una vez. */
    public function stops(): BelongsToMany
    {
        return $this->belongsToMany(Stop::class)->withPivot(['stop_sequence', 'verified_at'])->withTimestamps()->orderByPivot('stop_sequence');
    }
}
