<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DirectRouteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'origin' => ['required', 'string', 'different:destination', 'max:50', 'exists:stops,external_id'],
            'destination' => ['required', 'string', 'different:origin', 'max:50', 'exists:stops,external_id'],
        ];
    }
}
