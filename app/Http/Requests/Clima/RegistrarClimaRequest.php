<?php

namespace App\Http\Requests\Clima;

use Illuminate\Foundation\Http\FormRequest;

class RegistrarClimaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'cidade' => ['required', 'string', 'min:2', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'cidade.min' => 'O nome da cidade precisa ter pelo menos :min caracteres.',
            'cidade.max' => 'O nome da cidade pode ter no máximo :max caracteres.',
        ];
    }
}