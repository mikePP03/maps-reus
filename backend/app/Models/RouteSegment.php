<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RouteSegment extends Model
{
    protected $fillable = ['from_stop_id', 'to_stop_id', 'geometry', 'meters'];

    protected function casts(): array
    {
        return ['geometry' => 'array', 'meters' => 'integer'];
    }
}
