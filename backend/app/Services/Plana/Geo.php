<?php

namespace App\Services\Plana;

/** Cálculos de distancia compartidos por el importador y el planificador de Plana. */
final class Geo
{
    /** Distancia real a pie respecto a la línea recta. */
    public const WALK_DETOUR = 1.3;

    /** Metros por minuto a pie (4,5 km/h). */
    public const WALK_SPEED = 75;

    /** Distancia en línea recta, en metros (Haversine). */
    public static function meters(float $latA, float $lngA, float $latB, float $lngB): float
    {
        $dLat = deg2rad($latB - $latA);
        $dLng = deg2rad($lngB - $lngA);
        $h = sin($dLat / 2) ** 2 + cos(deg2rad($latA)) * cos(deg2rad($latB)) * sin($dLng / 2) ** 2;

        return 6371000 * 2 * asin(min(1, sqrt($h)));
    }

    /** Metros a pie estimados entre dos puntos (con el rodeo de las calles). */
    public static function walkMeters(float $latA, float $lngA, float $latB, float $lngB): int
    {
        return (int) round(self::meters($latA, $lngA, $latB, $lngB) * self::WALK_DETOUR);
    }

    /** Segundos a pie para una distancia ya estimada. */
    public static function walkSeconds(int $meters): int
    {
        return (int) ceil($meters / self::WALK_SPEED * 60);
    }
}
