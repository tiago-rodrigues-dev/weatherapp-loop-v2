<?php

namespace Tests\Feature;

use App\Models\ConsultaClima;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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
            'description' => 'céu limpo',
        ], $sobrescrever);

        return [
            'name' => $dados['name'],
            'main' => [
                'temp' => $dados['temp'],
                'feels_like' => $dados['feels_like'],
                'humidity' => $dados['humidity'],
            ],
            'weather' => [['description' => $dados['description']]],
        ];
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
}