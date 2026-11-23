<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Origen y destino como parada (`*_stop`) o como coordenadas (`*_lat` + `*_lng`). */
class JourneyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = ['walk_radius' => ['nullable', 'integer', 'min:100', 'max:2000']];
        foreach (['from', 'to'] as $side) {
            $rules += [
                "{$side}_stop" => ['nullable', 'string', 'max:50', "required_without_all:{$side}_lat,{$side}_lng", $this->stopExistsRule()],
                "{$side}_lat" => ['nullable', "required_without:{$side}_stop", "required_with:{$side}_lng", 'numeric', 'between:-90,90'],
                "{$side}_lng" => ['nullable', "required_without:{$side}_stop", "required_with:{$side}_lat", 'numeric', 'between:-180,180'],
                "{$side}_label" => ['nullable', 'string', 'max:120'],
            ];
        }

        return $rules;
    }

    /** Tabla donde debe existir la parada de origen o destino. */
    protected function stopExistsRule(): string
    {
        return 'exists:stops,external_id';
    }

    /** @return array{stop?:string,lat?:float,lng?:float,label?:string} */
    public function point(string $side): array
    {
        $point = array_filter([
            'stop' => $this->input("{$side}_stop"),
            'lat' => $this->filled("{$side}_lat") ? (float) $this->input("{$side}_lat") : null,
            'lng' => $this->filled("{$side}_lng") ? (float) $this->input("{$side}_lng") : null,
            'label' => $this->input("{$side}_label"),
        ], fn ($value) => $value !== null && $value !== '');

        // Si llegan ambas formas, manda la parada.
        return isset($point['stop']) ? array_diff_key($point, ['lat' => 0, 'lng' => 0]) : $point;
    }
}
