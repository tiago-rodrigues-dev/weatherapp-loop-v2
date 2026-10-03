<?php

namespace App\Models;

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
        'consultado_em',
    ];

    protected $hidden = ['cidade_slug', 'created_at', 'updated_at'];

    protected function casts(): array
    {
        return [
            'temperatura' => 'float',
            'sensacao_termica' => 'float',
            'umidade' => 'integer',
            'consultado_em' => 'datetime',
        ];
    }
}
