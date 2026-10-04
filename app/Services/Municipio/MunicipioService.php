<?php

namespace App\Services\Municipio;
use App\Repositories\Municipio\MunicipioRepository;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class MunicipioService
{
    public const LIMITE_RESULTADOS = 5;

    public function __construct(private readonly MunicipioRepository $municipioRepository) {}

    public function autocomplete(string $termo): Collection
    {
        $termoNormalizado = $this->normalizarTermo($termo);

        return $this->municipioRepository->buscarPorNome($termoNormalizado, self::LIMITE_RESULTADOS);
    }

    private function normalizarTermo(string $termo): string
    {
        return Str::lower(Str::ascii(trim($termo)));
    }
}