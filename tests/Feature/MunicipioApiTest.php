<?php

namespace Tests\Feature;

use App\Services\Municipio\MunicipioService;
use Database\Seeders\Municipio\MunicipioSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MunicipioApiTest extends TestCase
{
    use RefreshDatabase;

    private const ROTA = '/api/municipios';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MunicipioSeeder::class);
    }

    private function buscar(string $termo): TestResponse
    {
        return $this->getJson(self::ROTA.'?'.http_build_query(['busca' => $termo]));
    }

    private function normalizar(string $texto): string
    {
        return Str::lower(Str::ascii($texto));
    }

    private function totalDeCidadesNoJson(): int
    {
        $dados = json_decode(file_get_contents(base_path('cidades.json')), true);

        return collect($dados['estados'])->sum(fn (array $estado) => count($estado['cidades']));
    }

    #[Test]
    public function seeder_importa_todas_as_cidades_do_json_com_nome_normalizado(): void
    {
        $this->assertDatabaseCount('municipios', $this->totalDeCidadesNoJson());

        $this->assertDatabaseHas('municipios', [
            'nome' => 'Acrelândia',
            'nome_normalizado' => 'acrelandia',
            'uf' => 'AC',
        ]);
        $this->assertDatabaseHas('municipios', [
            'nome' => 'São Paulo',
            'nome_normalizado' => 'sao paulo',
            'uf' => 'SP',
        ]);
    }

    #[Test]
    public function seeder_e_idempotente(): void
    {
        $this->seed(MunicipioSeeder::class);

        $this->assertDatabaseCount('municipios', $this->totalDeCidadesNoJson());
    }

    #[Test]
    public function retorna_municipios_com_id_nome_e_uf_preenchidos(): void
    {
        $this->buscar('campinas')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonStructure(['data' => [['id', 'nome', 'uf']]])
            ->assertJsonPath('data.0.nome', 'Campinas')
            ->assertJsonPath('data.0.uf', 'SP')
            ->assertJsonFragment(['nome' => 'Campinas do Piauí', 'uf' => 'PI'])
            ->assertJsonFragment(['nome' => 'Campinas do Sul', 'uf' => 'RS']);
    }

    public static function variacoesDeEscrita(): array
    {
        return [
            'sem acento, minúsculo' => ['sao jose dos'],
            'com acento, minúsculo' => ['são josé dos'],
            'com acento, maiúsculo' => ['SÃO JOSÉ DOS'],
            'com espaços nas pontas' => ['  Sao Jose dos  '],
        ];
    }

    #[Test]
    #[DataProvider('variacoesDeEscrita')]
    public function busca_ignora_acentos_maiusculas_e_espacos(string $termo): void
    {
        $this->buscar($termo)
            ->assertOk()
            ->assertJsonCount(min(7, MunicipioService::LIMITE_RESULTADOS), 'data')
            ->assertJsonPath('data.0.nome', 'São José dos Ausentes')
            ->assertJsonPath('data.0.uf', 'RS');
    }

    #[Test]
    public function todos_os_resultados_contem_o_termo_buscado(): void
    {
        $nomes = $this->buscar('jose')->assertOk()->json('data.*.nome');

        $this->assertNotEmpty($nomes);

        foreach ($nomes as $nome) {
            $this->assertStringContainsString('jose', $this->normalizar($nome));
        }
    }

    #[Test]
    public function prioriza_nomes_que_comecam_com_o_termo(): void
    {
        $nomes = $this->buscar('jose')->assertOk()->json('data.*.nome');

        $comecaComOTermo = array_map(
            fn (string $nome) => str_starts_with($this->normalizar($nome), 'jose'),
            $nomes,
        );

        $esperado = array_slice(
            [...array_fill(0, 8, true), ...array_fill(0, 64, false)],
            0,
            MunicipioService::LIMITE_RESULTADOS,
        );

        $this->assertSame($esperado, $comecaComOTermo);
        $this->assertNotSame('Coronel José Dias', $nomes[0]);
    }

    #[Test]
    public function limita_a_quantidade_de_resultados(): void
    {
        $this->buscar('santa')
            ->assertOk()
            ->assertJsonCount(MunicipioService::LIMITE_RESULTADOS, 'data');
    }

    #[Test]
    public function retorna_lista_vazia_quando_nada_e_encontrado(): void
    {
        $this->buscar('xyzxyz')
            ->assertOk()
            ->assertExactJson(['data' => []]);
    }

    #[Test]
    public function retorna_422_quando_busca_nao_e_informada(): void
    {
        $this->getJson(self::ROTA)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['busca']);
    }

    #[Test]
    public function retorna_422_quando_busca_tem_menos_de_dois_caracteres(): void
    {
        $this->buscar('a')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['busca']);
    }

    #[Test]
    public function retorna_422_quando_busca_tem_mais_de_cem_caracteres(): void
    {
        $this->buscar(str_repeat('a', 101))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['busca']);
    }
}
