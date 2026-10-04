<?php

namespace App\Repositories\Municipio;

use App\Models\Municipio\Municipio;
use Illuminate\Support\Collection;

class MunicipioRepository
{
    public function buscarPorNome(string $termoNormalizado, int $limite = 10): Collection
    {
        return Municipio::query()
            ->where('nome_normalizado', 'like', "%$termoNormalizado%")
            ->orderByRAW('CASE WHEN nome_normalizado LIKE ? THEN 0 ELSE 1 END', ["$termoNormalizado%"])
            ->orderBy('nome')
            ->limit($limite)
            ->get('id', 'nome', 'uf');
    }
}