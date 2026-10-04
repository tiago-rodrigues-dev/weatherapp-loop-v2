<?php

namespace App\Services;

use App\DTOs\ResultadoRegistroClima;
use App\Providers\Weather\WeatherProviderInterface;
use App\Repositories\ConsultaClimaRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class ClimaService
{
    public const CACHE_TTL_SEGUNDOS = 600;

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
            $consulta = $this->repository->atualizarDataConsulta($ultima, $agora);
            $atualizado = true;
        } else {
            $consulta = $this->repository->criar($dados, $agora);
            $atualizado = false;
        }

        $this->limparCacheHistorico($dados->cidadeSlug());

        return new ResultadoRegistroClima($consulta, $atualizado);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function historico(?string $cidade = null): array
    {
        $slug = filled($cidade) ? Str::slug($cidade) : null;

        return Cache::remember(
            self::chaveCacheHistorico($slug),
            self::CACHE_TTL_SEGUNDOS,
            fn () => $this->repository->historico($slug)->toArray(),
        );
    }

    public static function chaveCacheHistorico(?string $cidadeSlug = null): string
    {
        return 'clima:historico:'.($cidadeSlug ?? 'todas');
    }

    private function limparCacheHistorico(string $cidadeSlug): void
    {
        Cache::forget(self::chaveCacheHistorico());
        Cache::forget(self::chaveCacheHistorico($cidadeSlug));
    }
}