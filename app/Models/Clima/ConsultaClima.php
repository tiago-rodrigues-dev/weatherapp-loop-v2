<?php

namespace App\Models\Clima;

use Illuminate\Database\Eloquent\Model;

class ConsultaClima extends Model
{
    protected $table = 'consulta_clima';

    protected $fillable = [
        'cidade',
        'cidade_slug',
        'temperatura',
        'sensacao_termica',
        'umidade',
        'descricao',
        'vento_kmh',
        'consultado_em',
    ];

    protected $hidden = ['cidade_slug', 'created_at', 'updated_at'];

    protected function casts(): array
    {
        return [
            'temperatura' => 'float',
            'sensacao_termica' => 'float',
            'umidade' => 'integer',
            'vento_kmh' => 'float',
            'consultado_em' => 'datetime',
        ];
    }
}
