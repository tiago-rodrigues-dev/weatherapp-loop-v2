<?php

namespace App\Repositories\Clima;

use App\DTOs\DadosClima;
use App\Models\Clima\ConsultaClima;
use DateTimeInterface;
use Illuminate\Support\Collection;

class ConsultaClimaRepository
{
    public function ultimaPorCidade(string $cidadeSlug): ?ConsultaClima
    {
        return ConsultaClima::query()
            ->where('cidade_slug', $cidadeSlug)
            ->orderByDesc('consultado_em')
            ->orderByDesc('id')
            ->first();
    }

    public function criar(DadosClima $dados, DateTimeInterface $consultadoEm): ConsultaClima
    {
        return ConsultaClima::create([
            'cidade' => $dados->cidade,
            'cidade_slug' => $dados->cidadeSlug(),
            'temperatura' => $dados->temperatura,
            'sensacao_termica' => $dados->sensacaoTermica,
            'umidade' => $dados->umidade,
            'descricao' => $dados->descricao,
            'consultado_em' => $consultadoEm,
        ]);
    }

    public function atualizarDataConsulta(ConsultaClima $consulta, DateTimeInterface $consultadoEm): ConsultaClima
    {
        $consulta->update(['consultado_em' => $consultadoEm]);

        return $consulta;
    }

    public function historico(?string $cidadeSlug = null): Collection
    {
        return ConsultaClima::query()
            ->when($cidadeSlug, fn ($query) => $query->where('cidade_slug', $cidadeSlug))
            ->orderByDesc('consultado_em')
            ->orderByDesc('id')
            ->get();
    }
}