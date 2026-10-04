<?php

namespace App\Repositories\Clima;

use App\DTOs\DadosClima;
use App\Models\Clima\ConsultaClima;
use DateTimeInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
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
            'vento_kmh' => $dados->ventoKmh,
            'consultado_em' => $consultadoEm,
        ]);
    }

    public function atualizarDataConsulta(ConsultaClima $consulta, DadosClima $dados, DateTimeInterface $consultadoEm): ConsultaClima
    {
        $consulta->update([
            'vento_kmh' => $dados->ventoKmh,
            'consultado_em' => $consultadoEm,
        ]);

        return $consulta;
    }

    /**
     * @param  array{cidade_slug: ?string, de: ?DateTimeInterface, ate: ?DateTimeInterface}  $filtros
     */
    public function historico(array $filtros, int $porPagina, int $pagina): LengthAwarePaginator
    {
        return ConsultaClima::query()
            ->when($filtros['cidade_slug'], fn ($query, $slug) => $query->where('cidade_slug', $slug))
            ->when($filtros['de'], fn ($query, $de) => $query->where('consultado_em', '>=', $de))
            ->when($filtros['ate'], fn ($query, $ate) => $query->where('consultado_em', '<=', $ate))
            ->orderByDesc('consultado_em')
            ->orderByDesc('id')
            ->paginate($porPagina, ['*'], 'page', $pagina);
    }

    public function cidadesConsultadas(): Collection
    {
        return ConsultaClima::query()
            ->distinct()
            ->orderBy('cidade')
            ->pluck('cidade');
    }
}