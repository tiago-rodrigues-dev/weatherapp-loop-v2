<?php

namespace App\Municipio\Models;

use Illuminate\Database\Eloquent\Model;

class Municipio extends Model
{
    protected $table = 'municipios';

    protected $fillable = ['nome', 'nome_normalizado', 'uf'];
}
