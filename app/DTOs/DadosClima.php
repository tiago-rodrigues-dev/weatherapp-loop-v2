<?php

namespace App\DTOs;

use App\Models\Clima\ConsultaClima;
use Illuminate\Support\Str;

final readonly class DadosClima
{
    public function __construct(
        public string $cidade,
        public float $temperatura,
        public float $sensacaoTermica,
        public int $umidade,
        public string $descricao,
    ) {}

    public function cidadeSlug(): string
    {
        return Str::slug($this->cidade);
    }

    public function igualA(ConsultaClima $consulta): bool
    {
        return round($this->temperatura, 2) === round((float) $consulta->temperatura, 2)
            && round($this->sensacaoTermica, 2) === round((float) $consulta->sensacao_termica, 2)
            && $this->umidade === (int) $consulta->umidade
            && $this->descricao === $consulta->descricao;
    }
}