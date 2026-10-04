<?php

namespace App\Services\Clima;

use App\Interfaces\WeatherProviderInterface;
use App\Repositories\Clima\ConsultaClimaRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class ClimaService
{
    public const CACHE_TTL_SEGUNDOS = 600;

    public const POR_PAGINA_PADRAO = 10;

    public const CHAVE_VERSAO_CACHE = 'clima:historico:versao';

    public function __construct(
        private readonly WeatherProviderInterface $provider,
        private readonly ConsultaClimaRepository $repository,
    ) {}

    public function registrarConsulta(string $cidade): ResultadoRegistroClima
    {
        $dados = $this->provider->buscarClimaAtual($cidade);
        $agora = now();

        $ultima = $this->repository->ultimaPorCidade($dados->cidadeSlug());

        if ($ultima !== null && $dados->igualA($ultima)) {
            $consulta = $this->repository->atualizarDataConsulta($ultima, $dados, $agora);
            $atualizado = true;
        } else {
            $consulta = $this->repository->criar($dados, $agora);
            $atualizado = false;
        }

        $this->invalidarCacheHistorico();

        return new ResultadoRegistroClima($consulta, $atualizado);
    }

    /**
     * @param  array{cidade?: ?string, de?: ?string, ate?: ?string, page?: int|string|null, per_page?: int|string|null}  $parametros
     * @return array<string, mixed> Paginação no formato padrão do Laravel (data, current_page, last_page, total...).
     */
    public function historico(array $parametros = []): array
    {
        $filtros = [
            'cidade_slug' => filled($parametros['cidade'] ?? null) ? Str::slug($parametros['cidade']) : null,
            'de' => $this->paraFusoDaAplicacao($parametros['de'] ?? null),
            'ate' => $this->paraFusoDaAplicacao($parametros['ate'] ?? null)?->endOfMinute(),
        ];
        $porPagina = (int) ($parametros['per_page'] ?? self::POR_PAGINA_PADRAO);
        $pagina = (int) ($parametros['page'] ?? 1);

        $chave = $this->chaveCache('lista', [
            'cidade_slug' => $filtros['cidade_slug'],
            'de' => $filtros['de']?->toDateTimeString(),
            'ate' => $filtros['ate']?->toDateTimeString(),
            'por_pagina' => $porPagina,
            'pagina' => $pagina,
        ]);

        return Cache::remember(
            $chave,
            self::CACHE_TTL_SEGUNDOS,
            fn () => $this->repository->historico($filtros, $porPagina, $pagina)->toArray(),
        );
    }

    /**
     * @return array<int, string>
     */
    public function cidades(): array
    {
        return Cache::remember(
            $this->chaveCache('cidades'),
            self::CACHE_TTL_SEGUNDOS,
            fn () => $this->repository->cidadesConsultadas()->all(),
        );
    }

    public function versaoCache(): int
    {
        return (int) Cache::get(self::CHAVE_VERSAO_CACHE, 1);
    }

    public function invalidarCacheHistorico(): void
    {
        Cache::forever(self::CHAVE_VERSAO_CACHE, $this->versaoCache() + 1);
    }

    private function chaveCache(string $tipo, array $parametros = []): string
    {
        return "clima:historico:v{$this->versaoCache()}:{$tipo}:".md5(json_encode($parametros));
    }

    private function paraFusoDaAplicacao(?string $data): ?Carbon
    {
        return filled($data) ? Carbon::parse($data)->setTimezone(config('app.timezone')) : null;
    }
}