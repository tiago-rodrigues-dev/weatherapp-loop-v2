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
            'de' => ['nullable', 'date'],
            'ate' => ['nullable', 'date', 'after_or_equal:de'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }

    public function messages(): array
    {
        return [
            'de.date' => 'A data inicial (de) é inválida.',
            'ate.date' => 'A data final (ate) é inválida.',
            'ate.after_or_equal' => 'A data final precisa ser igual ou posterior à data inicial.',
            'page.min' => 'A página precisa ser maior ou igual a 1.',
            'per_page.max' => 'São permitidos no máximo :max registros por página.',
        ];
    }
}