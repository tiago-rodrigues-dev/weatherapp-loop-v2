<?php

namespace App\Http\Requests\Municipio;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class BuscarMunicipioRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'busca' => ['required', 'string', 'min:2', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'busca.required' => 'O campo de busca é obrigatório.',
            'busca.min' => 'O campo de busca deve ter no mínimo :min caracteres.',
            'busca.max' => 'O campo de busca deve ter no máximo :max caracteres.',
        ];
    }


}
