<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ArrivalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['line' => ['required', 'string', 'regex:/^[Ll]?\d+$/', 'max:20'], 'stop' => ['required', 'string', 'regex:/^[\pL\pN_-]+$/u', 'max:50']];
    }
}
