<?php

namespace App\Http\Requests\Clima;

use Illuminate\Foundation\Http\FormRequest;

class HistoricoClimaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'cidade' => ['nullable', 'string', 'max:100'],
        ];
    }
}