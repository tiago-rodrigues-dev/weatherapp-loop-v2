<?php

namespace Tests\Unit;

use App\DTOs\DadosClima;
use App\Exceptions\CidadeNaoEncontradaException;
use App\Models\Clima\ConsultaClima;
use App\Interfaces\WeatherProviderInterface;
use App\Repositories\Clima\ConsultaClimaRepository;
use App\Services\Clima\ClimaService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ClimaServiceTest extends TestCase
{
    private WeatherProviderInterface&MockInterface $provider;

    private ConsultaClimaRepository&MockInterface $repository;

    private ClimaService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-03 12:00:00');

        $this->provider = Mockery::mock(WeatherProviderInterface::class);
        $this->repository = Mockery::mock(ConsultaClimaRepository::class);
        $this->service = new ClimaService($this->provider, $this->repository);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function dados(array $sobrescrever = []): DadosClima
    {
        return new DadosClima(...array_merge([
            'cidade' => 'Campinas',
            'temperatura' => 25.5,
            'sensacaoTermica' => 26.1,
            'umidade' => 60,
            'descricao' => 'céu limpo',
        ], $sobrescrever));
    }

    private function consultaExistente(array $sobrescrever = []): ConsultaClima
    {
        return (new ConsultaClima)->forceFill(array_merge([
            'id' => 1,
            'cidade' => 'Campinas',
            'cidade_slug' => 'campinas',
            'temperatura' => 25.5,
            'sensacao_termica' => 26.1,
            'umidade' => 60,
            'descricao' => 'céu limpo',
            'consultado_em' => '2026-10-03 11:00:00',
        ], $sobrescrever));
    }

    #[Test]
    public function cria_registro_quando_nao_existe_consulta_anterior(): void
    {
        $dados = $this->dados();
        $criada = $this->consultaExistente();

        $this->provider->shouldReceive('buscarClimaAtual')->once()->with('campinas')->andReturn($dados);
        $this->repository->shouldReceive('ultimaPorCidade')->once()->with('campinas')->andReturnNull();
        $this->repository->shouldReceive('criar')->once()
            ->with($dados, Mockery::on(fn ($data) => $data->equalTo(now())))
            ->andReturn($criada);
        $this->repository->shouldNotReceive('atualizarDataConsulta');

        $resultado = $this->service->registrarConsulta('campinas');

        $this->assertFalse($resultado->atualizado);
        $this->assertSame($criada, $resultado->consulta);
    }

    #[Test]
    public function apenas_atualiza_data_quando_ultima_consulta_tem_os_mesmos_valores(): void
    {
        $existente = $this->consultaExistente();

        $this->provider->shouldReceive('buscarClimaAtual')->andReturn($this->dados());
        $this->repository->shouldReceive('ultimaPorCidade')->with('campinas')->andReturn($existente);
        $this->repository->shouldReceive('atualizarDataConsulta')->once()
            ->with($existente, Mockery::on(fn ($data) => $data->equalTo(now())))
            ->andReturn($existente);
        $this->repository->shouldNotReceive('criar');

        $resultado = $this->service->registrarConsulta('Campinas');

        $this->assertTrue($resultado->atualizado);
        $this->assertSame($existente, $resultado->consulta);
    }

    public static function camposAlterados(): array
    {
        return [
            'temperatura mudou' => [['temperatura' => 27.0]],
            'sensação térmica mudou' => [['sensacaoTermica' => 28.3]],
            'umidade mudou' => [['umidade' => 75]],
            'descrição mudou' => [['descricao' => 'nublado']],
        ];
    }

    #[Test]
    #[DataProvider('camposAlterados')]
    public function cria_novo_registro_quando_algum_valor_muda(array $alteracao): void
    {
        $dados = $this->dados($alteracao);

        $this->provider->shouldReceive('buscarClimaAtual')->andReturn($dados);
        $this->repository->shouldReceive('ultimaPorCidade')->andReturn($this->consultaExistente());
        $this->repository->shouldReceive('criar')->once()->andReturn($this->consultaExistente(['id' => 2]));
        $this->repository->shouldNotReceive('atualizarDataConsulta');

        $resultado = $this->service->registrarConsulta('Campinas');

        $this->assertFalse($resultado->atualizado);
    }

    #[Test]
    public function nao_grava_nada_quando_o_provider_falha(): void
    {
        $this->provider->shouldReceive('buscarClimaAtual')
            ->andThrow(CidadeNaoEncontradaException::para('Xyzabc'));
        $this->repository->shouldNotReceive('ultimaPorCidade');
        $this->repository->shouldNotReceive('criar');
        $this->repository->shouldNotReceive('atualizarDataConsulta');

        $this->expectException(CidadeNaoEncontradaException::class);

        $this->service->registrarConsulta('Xyzabc');
    }

    #[Test]
    public function historico_usa_cache_na_segunda_chamada(): void
    {
        $this->repository->shouldReceive('historico')->once()->with(null)
            ->andReturn(collect([$this->consultaExistente()]));

        $primeira = $this->service->historico();
        $segunda = $this->service->historico();

        $this->assertCount(1, $primeira);
        $this->assertSame($primeira, $segunda);
        $this->assertTrue(Cache::has(ClimaService::chaveCacheHistorico()));
    }

    #[Test]
    public function historico_filtrado_usa_chave_de_cache_propria_pelo_slug(): void
    {
        $this->repository->shouldReceive('historico')->once()->with('sao-paulo')->andReturn(collect());
        $this->repository->shouldReceive('historico')->once()->with(null)->andReturn(collect());

        $this->service->historico('São Paulo');
        $this->service->historico('sao paulo');
        $this->service->historico();

        $this->assertTrue(Cache::has('clima:historico:sao-paulo'));
        $this->assertTrue(Cache::has('clima:historico:todas'));
    }

    #[Test]
    public function historico_guarda_arrays_simples_no_cache(): void
    {
        $this->repository->shouldReceive('historico')->andReturn(collect([$this->consultaExistente()]));

        $this->service->historico();

        $this->assertIsArray(Cache::get(ClimaService::chaveCacheHistorico())[0]);
    }

    #[Test]
    public function invalida_cache_do_historico_apos_gravar(): void
    {
        Cache::put(ClimaService::chaveCacheHistorico(), ['antigo'], 600);
        Cache::put(ClimaService::chaveCacheHistorico('campinas'), ['antigo'], 600);
        Cache::put(ClimaService::chaveCacheHistorico('recife'), ['antigo'], 600);

        $this->provider->shouldReceive('buscarClimaAtual')->andReturn($this->dados());
        $this->repository->shouldReceive('ultimaPorCidade')->andReturnNull();
        $this->repository->shouldReceive('criar')->andReturn($this->consultaExistente());

        $this->service->registrarConsulta('Campinas');

        $this->assertFalse(Cache::has(ClimaService::chaveCacheHistorico()));
        $this->assertFalse(Cache::has(ClimaService::chaveCacheHistorico('campinas')));
        $this->assertTrue(Cache::has(ClimaService::chaveCacheHistorico('recife')));
    }

    #[Test]
    public function invalida_cache_tambem_quando_so_atualiza_a_data(): void
    {
        Cache::put(ClimaService::chaveCacheHistorico(), ['antigo'], 600);

        $existente = $this->consultaExistente();
        $this->provider->shouldReceive('buscarClimaAtual')->andReturn($this->dados());
        $this->repository->shouldReceive('ultimaPorCidade')->andReturn($existente);
        $this->repository->shouldReceive('atualizarDataConsulta')->andReturn($existente);

        $this->service->registrarConsulta('Campinas');

        $this->assertFalse(Cache::has(ClimaService::chaveCacheHistorico()));
    }
}