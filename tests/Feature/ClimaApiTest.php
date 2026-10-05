<?php

namespace Tests\Feature;

use App\Models\Clima\ConsultaClima;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ClimaApiTest extends TestCase
{
    use RefreshDatabase;

    private const URL_OPENWEATHER = 'api.openweathermap.org/*';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.openweather.key' => 'chave-de-teste']);
        Http::preventStrayRequests();
        $this->travelTo('2026-10-03 12:00:00');
    }

    private function respostaOpenWeather(array $sobrescrever = []): array
    {
        $dados = array_merge([
            'name' => 'Campinas',
            'temp' => 25.5,
            'feels_like' => 26.1,
            'humidity' => 60,
            'wind_speed' => 4.0,
            'description' => 'céu limpo',
        ], $sobrescrever);

        return [
            'name' => $dados['name'],
            'main' => [
                'temp' => $dados['temp'],
                'feels_like' => $dados['feels_like'],
                'humidity' => $dados['humidity'],
            ],
            'wind' => ['speed' => $dados['wind_speed']],
            'weather' => [['description' => $dados['description']]],
        ];
    }

    private function criarConsulta(string $cidade, float $temperatura, string $consultadoEm): ConsultaClima
    {
        return ConsultaClima::create([
            'cidade' => $cidade,
            'cidade_slug' => Str::slug($cidade),
            'temperatura' => $temperatura,
            'sensacao_termica' => $temperatura,
            'umidade' => 50,
            'descricao' => 'céu limpo',
            'consultado_em' => $consultadoEm,
        ]);
    }

    #[Test]
    public function registra_consulta_e_retorna_201(): void
    {
        Http::fake([self::URL_OPENWEATHER => Http::response($this->respostaOpenWeather())]);

        $this->postJson('/api/clima?cidade=campinas')
            ->assertCreated()
            ->assertJsonPath('atualizado', false)
            ->assertJsonPath('data.cidade', 'Campinas')
            ->assertJsonPath('data.temperatura', 25.5)
            ->assertJsonPath('data.umidade', 60)
            ->assertJsonMissingPath('data.cidade_slug');

        $this->assertDatabaseHas('consulta_clima', [
            'cidade' => 'Campinas',
            'cidade_slug' => 'campinas',
            'descricao' => 'céu limpo',
        ]);

        Http::assertSent(fn ($request) => $request['q'] === 'campinas,BR'
            && $request['units'] === 'metric'
            && $request['appid'] === 'chave-de-teste');
    }

    #[Test]
    public function consulta_repetida_com_mesmos_valores_so_atualiza_a_data(): void
    {
        Http::fake([self::URL_OPENWEATHER => Http::response($this->respostaOpenWeather())]);

        $this->postJson('/api/clima?cidade=Campinas')->assertCreated();

        $this->travel(30)->minutes();

        $this->postJson('/api/clima?cidade=Campinas')
            ->assertOk()
            ->assertJsonPath('atualizado', true);

        $this->assertDatabaseCount('consulta_clima', 1);
        $this->assertEquals(
            '2026-10-03 12:30:00',
            ConsultaClima::first()->consultado_em->format('Y-m-d H:i:s'),
        );
    }

    #[Test]
    public function consulta_com_temperatura_diferente_cria_novo_registro(): void
    {
        Http::fakeSequence(self::URL_OPENWEATHER)
            ->push($this->respostaOpenWeather())
            ->push($this->respostaOpenWeather(['temp' => 27.0]));

        $this->postJson('/api/clima?cidade=Campinas')->assertCreated();
        $this->postJson('/api/clima?cidade=Campinas')->assertCreated();

        $this->assertDatabaseCount('consulta_clima', 2);
    }

    #[Test]
    public function retorna_422_quando_cidade_nao_e_informada(): void
    {
        Http::fake();

        $this->postJson('/api/clima')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['cidade']);

        Http::assertNothingSent();
    }

    #[Test]
    public function retorna_404_quando_cidade_nao_existe_na_openweather(): void
    {
        Http::fake([self::URL_OPENWEATHER => Http::response(['cod' => '404', 'message' => 'city not found'], 404)]);

        $this->postJson('/api/clima?cidade=Xyzabc')
            ->assertNotFound()
            ->assertJson(['erro' => 'Cidade "Xyzabc" não encontrada no serviço de clima.']);

        $this->assertDatabaseCount('consulta_clima', 0);
    }

    #[Test]
    public function retorna_502_quando_a_chave_da_api_e_invalida(): void
    {
        Http::fake([self::URL_OPENWEATHER => Http::response(['cod' => 401], 401)]);

        $this->postJson('/api/clima?cidade=Campinas')
            ->assertStatus(502)
            ->assertJsonStructure(['erro']);
    }

    #[Test]
    public function retorna_502_quando_a_openweather_retorna_erro_interno(): void
    {
        Http::fake([self::URL_OPENWEATHER => Http::response('erro', 500)]);

        $this->postJson('/api/clima?cidade=Campinas')
            ->assertStatus(502)
            ->assertJson(['erro' => 'O serviço de clima retornou um erro inesperado.']);
    }

    #[Test]
    public function retorna_502_quando_a_resposta_vem_em_formato_inesperado(): void
    {
        Http::fake([self::URL_OPENWEATHER => Http::response(['foo' => 'bar'])]);

        $this->postJson('/api/clima?cidade=Campinas')->assertStatus(502);
    }

    #[Test]
    public function retorna_502_quando_a_chave_nao_esta_configurada(): void
    {
        config(['services.openweather.key' => null]);
        Http::fake();

        $this->postJson('/api/clima?cidade=Campinas')->assertStatus(502);

        Http::assertNothingSent();
    }

    #[Test]
    public function retorna_503_quando_a_openweather_esta_inacessivel(): void
    {
        Http::fake([self::URL_OPENWEATHER => Http::failedConnection()]);

        $this->postJson('/api/clima?cidade=Campinas')
            ->assertServiceUnavailable()
            ->assertJsonStructure(['erro']);
    }

    #[Test]
    public function historico_lista_do_mais_recente_para_o_mais_antigo(): void
    {
        Http::fakeSequence(self::URL_OPENWEATHER)
            ->push($this->respostaOpenWeather())
            ->push($this->respostaOpenWeather(['name' => 'Recife', 'temp' => 30.0]));

        $this->postJson('/api/clima?cidade=Campinas');
        $this->travel(5)->minutes();
        $this->postJson('/api/clima?cidade=Recife');

        $this->getJson('/api/clima/historico')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.cidade', 'Recife')
            ->assertJsonPath('data.1.cidade', 'Campinas');
    }

    #[Test]
    public function historico_filtra_por_cidade_ignorando_acentos(): void
    {
        Http::fakeSequence(self::URL_OPENWEATHER)
            ->push($this->respostaOpenWeather(['name' => 'São Paulo']))
            ->push($this->respostaOpenWeather(['name' => 'Recife']));

        $this->postJson('/api/clima?cidade=Sao Paulo');
        $this->postJson('/api/clima?cidade=Recife');

        $this->getJson('/api/clima/historico?cidade=sao paulo')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.cidade', 'São Paulo');
    }

    #[Test]
    public function historico_reflete_nova_consulta_apos_invalidar_cache(): void
    {
        Http::fakeSequence(self::URL_OPENWEATHER)
            ->push($this->respostaOpenWeather())
            ->push($this->respostaOpenWeather(['temp' => 18.0]));

        $this->postJson('/api/clima?cidade=Campinas');
        $this->getJson('/api/clima/historico')->assertJsonCount(1, 'data');

        $this->postJson('/api/clima?cidade=Campinas');
        $this->getJson('/api/clima/historico')->assertJsonCount(2, 'data');
    }

    #[Test]
    public function historico_funciona_com_cache_em_banco_de_dados(): void
    {
        config(['cache.default' => 'database']);
        Http::fake([self::URL_OPENWEATHER => Http::response($this->respostaOpenWeather())]);

        $this->postJson('/api/clima?cidade=Campinas');

        $this->getJson('/api/clima/historico')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/clima/historico')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.cidade', 'Campinas');
    }

        #[Test]
    public function salva_vento_em_kmh(): void
    {
        Http::fake([self::URL_OPENWEATHER => Http::response($this->respostaOpenWeather())]);

        $this->postJson('/api/clima?cidade=Campinas')
            ->assertCreated()
            ->assertJsonPath('data.vento_kmh', 14.4)
            ->assertJsonMissingPath('data.pressao_hpa');
    }

    #[Test]
    public function salva_vento_nulo_quando_a_openweather_nao_envia(): void
    {
        $resposta = $this->respostaOpenWeather();
        unset($resposta['wind']);
        Http::fake([self::URL_OPENWEATHER => Http::response($resposta)]);

        $this->postJson('/api/clima?cidade=Campinas')
            ->assertCreated()
            ->assertJsonPath('data.vento_kmh', null);
    }

    #[Test]
    public function consulta_redundante_atualiza_vento_sem_criar_registro(): void
    {
        Http::fakeSequence(self::URL_OPENWEATHER)
            ->push($this->respostaOpenWeather())
            ->push($this->respostaOpenWeather(['wind_speed' => 10.0]));

        $this->postJson('/api/clima?cidade=Campinas')->assertCreated();
        $this->postJson('/api/clima?cidade=Campinas')
            ->assertOk()
            ->assertJsonPath('atualizado', true)
            ->assertJsonPath('data.vento_kmh', 36);

        $this->assertDatabaseCount('consulta_clima', 1);
    }

    #[Test]
    public function historico_e_paginado(): void
    {
        $this->criarConsulta('Campinas', 20, '2026-10-03 09:00:00');
        $this->criarConsulta('Campinas', 21, '2026-10-03 10:00:00');
        $this->criarConsulta('Recife', 30, '2026-10-03 11:00:00');

        $this->getJson('/api/clima/historico?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.cidade', 'Recife')
            ->assertJsonPath('current_page', 1)
            ->assertJsonPath('last_page', 2)
            ->assertJsonPath('per_page', 2)
            ->assertJsonPath('total', 3)
            ->assertJsonPath('from', 1)
            ->assertJsonPath('to', 2);

        $this->getJson('/api/clima/historico?per_page=2&page=2')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.temperatura', 20);
    }

    #[Test]
    public function historico_usa_dez_por_pagina_por_padrao(): void
    {
        foreach (range(1, 12) as $i) {
            $this->criarConsulta('Campinas', 20 + $i, '2026-10-03 '.str_pad((string) $i, 2, '0', STR_PAD_LEFT).':00:00');
        }

        $this->getJson('/api/clima/historico')
            ->assertOk()
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('total', 12);
    }

    #[Test]
    public function historico_filtra_por_periodo_incluindo_o_minuto_final(): void
    {
        $this->criarConsulta('Campinas', 20, '2026-10-01 08:00:00');
        $this->criarConsulta('Campinas', 21, '2026-10-02 10:15:00');
        $this->criarConsulta('Campinas', 22, '2026-10-02 10:17:45');
        $this->criarConsulta('Campinas', 23, '2026-10-03 09:00:00');

        $this->getJson('/api/clima/historico?'.http_build_query([
            'de' => '2026-10-02T10:00:00Z',
            'ate' => '2026-10-02T10:17:00Z',
        ]))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.temperatura', 22)
            ->assertJsonPath('data.1.temperatura', 21);
    }

    #[Test]
    public function historico_converte_o_fuso_horario_do_filtro(): void
    {
        $this->criarConsulta('Campinas', 20, '2026-10-02 12:30:00');

        $this->getJson('/api/clima/historico?'.http_build_query([
            'de' => '2026-10-02T09:00:00-03:00',
            'ate' => '2026-10-02T09:59:00-03:00',
        ]))
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    #[Test]
    public function historico_combina_filtro_de_cidade_e_periodo(): void
    {
        $this->criarConsulta('Campinas', 20, '2026-10-02 10:00:00');
        $this->criarConsulta('Recife', 30, '2026-10-02 10:00:00');
        $this->criarConsulta('Campinas', 22, '2026-10-03 10:00:00');

        $this->getJson('/api/clima/historico?'.http_build_query([
            'cidade' => 'campinas',
            'de' => '2026-10-02T00:00:00Z',
            'ate' => '2026-10-02T23:59:00Z',
        ]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.cidade', 'Campinas')
            ->assertJsonPath('data.0.temperatura', 20);
    }

    #[Test]
    public function historico_retorna_422_para_parametros_invalidos(): void
    {
        $this->getJson('/api/clima/historico?'.http_build_query(['de' => '2026-10-03T10:00:00Z', 'ate' => '2026-10-02T10:00:00Z']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['ate']);

        $this->getJson('/api/clima/historico?de=ontem-de-manha')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['de']);

        $this->getJson('/api/clima/historico?per_page=51')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['per_page']);

        $this->getJson('/api/clima/historico?page=0')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['page']);
    }

    #[Test]
    public function lista_cidades_consultadas_sem_repetir_e_em_ordem_alfabetica(): void
    {
        $this->criarConsulta('Recife', 30, '2026-10-02 10:00:00');
        $this->criarConsulta('Campinas', 20, '2026-10-02 11:00:00');
        $this->criarConsulta('Campinas', 21, '2026-10-02 12:00:00');

        $this->getJson('/api/clima/cidades')
            ->assertOk()
            ->assertExactJson(['data' => ['Campinas', 'Recife']]);
    }

    #[Test]
    public function lista_de_cidades_reflete_nova_consulta(): void
    {
        Http::fake([self::URL_OPENWEATHER => Http::response($this->respostaOpenWeather(['name' => 'Jales']))]);

        $this->getJson('/api/clima/cidades')->assertExactJson(['data' => []]);

        $this->postJson('/api/clima?cidade=Jales')->assertCreated();

        $this->getJson('/api/clima/cidades')->assertExactJson(['data' => ['Jales']]);
    }
}
