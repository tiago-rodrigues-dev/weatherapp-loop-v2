<?php

namespace Tests\Unit;

use App\DTOs\DadosClima;
use App\Exceptions\CidadeNaoEncontradaException;
use App\Interfaces\WeatherProviderInterface;
use App\Models\Clima\ConsultaClima;
use App\Repositories\Clima\ConsultaClimaRepository;
use App\Services\Clima\ClimaService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
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
            'ventoKmh' => 14.4,
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
            'vento_kmh' => 10.8,
            'consultado_em' => '2026-10-03 11:00:00',
        ], $sobrescrever));
    }

    private function paginaCom(array $consultas): LengthAwarePaginator
    {
        return new LengthAwarePaginator(collect($consultas), count($consultas), 10, 1);
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
    public function apenas_atualiza_quando_ultima_consulta_tem_os_mesmos_valores(): void
    {
        $existente = $this->consultaExistente();
        $dados = $this->dados();

        $this->provider->shouldReceive('buscarClimaAtual')->andReturn($dados);
        $this->repository->shouldReceive('ultimaPorCidade')->with('campinas')->andReturn($existente);
        $this->repository->shouldReceive('atualizarDataConsulta')->once()
            ->with($existente, $dados, Mockery::on(fn ($data) => $data->equalTo(now())))
            ->andReturn($existente);
        $this->repository->shouldNotReceive('criar');

        $resultado = $this->service->registrarConsulta('Campinas');

        $this->assertTrue($resultado->atualizado);
        $this->assertSame($existente, $resultado->consulta);
    }

    #[Test]
    public function vento_diferente_nao_gera_novo_registro(): void
    {
        $existente = $this->consultaExistente();

        $this->provider->shouldReceive('buscarClimaAtual')
            ->andReturn($this->dados(['ventoKmh' => 30.0]));
        $this->repository->shouldReceive('ultimaPorCidade')->andReturn($existente);
        $this->repository->shouldReceive('atualizarDataConsulta')->once()->andReturn($existente);
        $this->repository->shouldNotReceive('criar');

        $this->assertTrue($this->service->registrarConsulta('Campinas')->atualizado);
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
        $this->provider->shouldReceive('buscarClimaAtual')->andReturn($this->dados($alteracao));
        $this->repository->shouldReceive('ultimaPorCidade')->andReturn($this->consultaExistente());
        $this->repository->shouldReceive('criar')->once()->andReturn($this->consultaExistente(['id' => 2]));
        $this->repository->shouldNotReceive('atualizarDataConsulta');

        $this->assertFalse($this->service->registrarConsulta('Campinas')->atualizado);
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
    public function historico_usa_cache_na_segunda_chamada_com_os_mesmos_filtros(): void
    {
        $this->repository->shouldReceive('historico')->once()
            ->with(['cidade_slug' => null, 'de' => null, 'ate' => null], ClimaService::POR_PAGINA_PADRAO, 1)
            ->andReturn($this->paginaCom([$this->consultaExistente()]));

        $primeira = $this->service->historico();
        $segunda = $this->service->historico();

        $this->assertSame($primeira, $segunda);
        $this->assertCount(1, $primeira['data']);
        $this->assertSame(1, $primeira['total']);
    }

    #[Test]
    public function cada_combinacao_de_filtros_tem_seu_proprio_cache(): void
    {
        $this->repository->shouldReceive('historico')->times(3)->andReturn($this->paginaCom([]));

        $this->service->historico(['cidade' => 'Campinas']);
        $this->service->historico(['cidade' => 'Campinas', 'page' => 2]);
        $this->service->historico(['cidade' => 'Campinas', 'per_page' => 5]);
        $this->service->historico(['cidade' => 'campinas']);
    }

    #[Test]
    public function normaliza_filtros_antes_de_consultar_o_repository(): void
    {
        $this->repository->shouldReceive('historico')->once()
            ->with(Mockery::on(function (array $filtros) {
                return $filtros['cidade_slug'] === 'sao-paulo'
                    && $filtros['de']->toDateTimeString() === '2026-10-01 13:00:00'
                    && $filtros['ate']->toDateTimeString() === '2026-10-02 13:30:59';
            }), 5, 2)
            ->andReturn($this->paginaCom([]));

        $this->service->historico([
            'cidade' => 'São Paulo',
            'de' => '2026-10-01T10:00:00-03:00',
            'ate' => '2026-10-02T13:30:00Z',
            'per_page' => '5',
            'page' => '2',
        ]);
    }

    #[Test]
    public function historico_guarda_arrays_simples_no_cache(): void
    {
        $this->repository->shouldReceive('historico')->andReturn($this->paginaCom([$this->consultaExistente()]));

        $resultado = $this->service->historico();

        $this->assertIsArray($resultado['data'][0]);
    }

    #[Test]
    public function cidades_consultadas_usam_cache(): void
    {
        $this->repository->shouldReceive('cidadesConsultadas')->once()->andReturn(collect(['Campinas', 'Recife']));

        $this->assertSame(['Campinas', 'Recife'], $this->service->cidades());
        $this->assertSame(['Campinas', 'Recife'], $this->service->cidades());
    }

    #[Test]
    public function gravar_invalida_o_cache_do_historico_e_das_cidades(): void
    {
        $this->repository->shouldReceive('historico')->twice()->andReturn($this->paginaCom([]));
        $this->repository->shouldReceive('cidadesConsultadas')->twice()->andReturn(collect());
        $this->provider->shouldReceive('buscarClimaAtual')->andReturn($this->dados());
        $this->repository->shouldReceive('ultimaPorCidade')->andReturnNull();
        $this->repository->shouldReceive('criar')->andReturn($this->consultaExistente());

        $this->service->historico();
        $this->service->cidades();
        $versaoAntes = $this->service->versaoCache();

        $this->service->registrarConsulta('Campinas');

        $this->assertSame($versaoAntes + 1, $this->service->versaoCache());
        $this->service->historico();
        $this->service->cidades();
    }

    #[Test]
    public function invalida_cache_tambem_quando_so_atualiza_a_data(): void
    {
        $existente = $this->consultaExistente();
        $this->provider->shouldReceive('buscarClimaAtual')->andReturn($this->dados());
        $this->repository->shouldReceive('ultimaPorCidade')->andReturn($existente);
        $this->repository->shouldReceive('atualizarDataConsulta')->andReturn($existente);

        $versaoAntes = $this->service->versaoCache();

        $this->service->registrarConsulta('Campinas');

        $this->assertSame($versaoAntes + 1, $this->service->versaoCache());
    }
}