# Tarefa parte 2: Interface React integrada à API

Guia de implementação do front-end do WeatherApp. Cada arquivo aparece com uma breve descrição e o código completo, na ordem sugerida, no mesmo formato do `implementacao.md`.

**Tudo abaixo foi validado** numa cópia do projeto (com os seus namespaces atuais):
- `php artisan test`: **57 testes / 202 asserções passando**, com ou sem `npm run build`. Antes eram 43; entraram testes de paginação, filtros, cidades e velocidade do vento, e o `ExampleTest` foi trocado por um teste da página inicial.
- `npx tsc --noEmit` sem erros e `npm run build` concluindo com sucesso.
- Teste ponta a ponta no Chrome headless, com uma OpenWeather falsa local:
  - o autocomplete sugere "Jales, SP";
  - Consultar seleciona o registro novo;
  - uma consulta repetida mostra o aviso de "só atualizou a data";
  - Cancelar funciona;
  - uma cidade inexistente mostra o banner 404;
  - o filtro por cidade e período e a paginação funcionam;
  - no celular (390 px) não há rolagem horizontal;
  - nenhum erro no console.

## Visão geral

```
GET  /                                   → view Blade que monta o React (resources/js/main.tsx)
GET  /api/municipios?busca=jal           → autocomplete (já existia)
POST /api/clima?cidade=Jales             → consulta e grava (já existia; agora também salva a velocidade do vento)
GET  /api/clima/historico?cidade=&de=&ate=&page=&per_page=   → histórico PAGINADO e filtrado (mudou)
GET  /api/clima/cidades                  → cidades já consultadas, para o select do filtro (novo)
```

Telas e componentes:

```
App
├── Header (LogoLoop)
├── BarraConsulta ── useAutocomplete ── GET /api/municipios
├── Alerta (erro / aviso)
└── PainelHistorico
    ├── FiltrosHistorico ── useCidadesConsultadas ── GET /api/clima/cidades
    ├── TabelaHistorico + Paginacao ── useHistorico ── GET /api/clima/historico
    └── CardTelemetria ── useConsultasDaCidade ── GET /api/clima/historico?cidade=X&per_page=50
        ├── WidgetCondicao (ícone pela descrição)
        ├── CurvaTemperatura (SVG)
        └── MiniEstatisticas (mínima / média / máxima)
```

### Decisões importantes
- **Tabela:** Cidade, Consulta (data/hora), Temp., Sensação, Umidade e Descrição. Sem País, sem Observação e sem chip de status, como você pediu.
- **Ícone da condição:** escolhido pelo **texto da descrição** (`utils/iconeClima.ts`), seguindo o catálogo de ícones e cores. Descrições desconhecidas usam `device_thermostat`. Não diferencia dia e noite.
- **Velocidade do vento:** coluna nova e nullable (`vento_kmh`). A OpenWeather manda o vento em m/s (com `units=metric`), e convertemos para km/h (×3,6). A pressão atmosférica **não** é salva nem exibida. A **regra de duplicidade não mudou**: só temperatura, sensação, umidade e descrição contam. Quando o registro é redundante, atualizamos a data/hora **e** o vento. Registros antigos (ou respostas sem vento) ficam com `null`, e a tela mostra "—".
- **Paginação e filtros na API:** a resposta do histórico agora tem o formato padrão do paginator do Laravel (`data`, `current_page`, `last_page`, `per_page`, `total`, `from`, `to`…). O padrão é 10 por página, com máximo de 50. `de` e `ate` filtram o `consultado_em`, e o `ate` inclui o minuto inteiro (10:17 vai até 10:17:59).
- **Fuso horário:** o banco guarda em UTC (`config/app.php`). O front converte o `datetime-local` (horário local) para ISO em UTC com `toISOString()`, e a API converte para o fuso da aplicação antes de filtrar. A tabela mostra os horários no fuso do navegador.
- **Cache versionado:** como o store `database` não suporta tags, cada combinação de filtros tem a sua chave (`clima:historico:v{versao}:...`). Toda gravação incrementa `clima:historico:versao`, o que invalida tudo de uma vez. O cache continua guardando **arrays**, por causa do `serializable_classes => false`.
- **Curva, Mínima, Média e Máxima:** cada ponto da curva é uma consulta da cidade selecionada, respeitando o período filtrado, com até 50 consultas, calculadas no front. Com uma única consulta, aparece um aviso no lugar da curva.
- **Badge do card:** em vez de "Tempo Real", o card mostra **"Última leitura"** quando o registro é o mais recente da cidade e **"Leitura anterior"** quando não é. Os dados não são em tempo real.
- **Autocomplete:** a sugestão mostra "Jales, SP", mas o POST envia só `cidade=Jales`, mantendo a decisão da parte 1.
- **Cancelar:** aborta a **espera** no navegador (`AbortController`). Se o servidor já recebeu a requisição, ele termina e grava o registro, que aparece ao recarregar. No teste ponta a ponta, uma consulta cancelada apareceu depois no histórico.

> ⚠️ **Antes de testar, rode `php artisan optimize:clear`.** O seu projeto tem cache de rotas (`bootstrap/cache/routes-v7.php`); sem limpar, a rota nova `/api/clima/cidades` e a rota `/` da view respondem 404. Na validação, foi exatamente isso que aconteceu.

> ⚠️ **A `OPENWEATHER_API_KEY` do seu `.env` está sendo recusada pela OpenWeather (HTTP 401).** A API responde corretamente "Falha de autenticação com o serviço de clima. Verifique a chave da API.". Chaves novas podem levar algumas horas para ativar. Confira a chave no painel da OpenWeather.

---

## Parte A: Backend (velocidade do vento, filtros e paginação)

### `database/migrations/2026_10_04_000001_add_vento_kmh_to_consulta_clima_table.php`

Migration nova: adiciona `vento_kmh` à `consulta_clima`. É nullable, porque os registros antigos não têm esse dado.

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consulta_clima', function (Blueprint $table) {
            $table->decimal('vento_kmh', 5, 1)->nullable()->after('descricao');
        });
    }

    public function down(): void
    {
        Schema::table('consulta_clima', function (Blueprint $table) {
            $table->dropColumn('vento_kmh');
        });
    }
};
```

### `app/DTOs/DadosClima.php`

Ganha `ventoKmh`, **opcional** (com `null` como padrão) para não quebrar quem cria o DTO sem ele. O `igualA()` **não mudou**: o vento não conta para a duplicidade.

```php
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
        public ?float $ventoKmh = null,
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
```

### `app/Providers/Weather/OpenWeatherProvider.php (método mapear)`

Substitua só o método `mapear()`, que agora lê `wind.speed` (m/s → km/h). O resto do arquivo continua igual.

```php
    private function mapear(?array $dados): DadosClima
    {
        if (! isset($dados['name'], $dados['main']['temp'], $dados['main']['feels_like'], $dados['main']['humidity'], $dados['weather'][0]['description'])) {
            throw new FalhaApiClimaException('O serviço de clima retornou uma resposta em formato inesperado.');
        }

        return new DadosClima(
            cidade: $dados['name'],
            temperatura: (float) $dados['main']['temp'],
            sensacaoTermica: (float) $dados['main']['feels_like'],
            umidade: (int) $dados['main']['humidity'],
            descricao: $dados['weather'][0]['description'],
            ventoKmh: isset($dados['wind']['speed']) ? round($dados['wind']['speed'] * 3.6, 1) : null,
        );
    }
```

### `app/Models/Clima/ConsultaClima.php`

Inclui `vento_kmh` no `$fillable` e nos `casts`.

```php
<?php

namespace App\Models\Clima;

use Illuminate\Database\Eloquent\Model;

class ConsultaClima extends Model
{
    protected $table = 'consulta_clima';

    protected $fillable = [
        'cidade',
        'cidade_slug',
        'temperatura',
        'sensacao_termica',
        'umidade',
        'descricao',
        'vento_kmh',
        'consultado_em',
    ];

    protected $hidden = ['cidade_slug', 'created_at', 'updated_at'];

    protected function casts(): array
    {
        return [
            'temperatura' => 'float',
            'sensacao_termica' => 'float',
            'umidade' => 'integer',
            'vento_kmh' => 'float',
            'consultado_em' => 'datetime',
        ];
    }
}
```

### `app/Repositories/Clima/ConsultaClimaRepository.php`

Mudanças:
- `criar()` grava o vento;
- `atualizarDataConsulta()` **ganhou o parâmetro `DadosClima $dados`** para atualizar o vento junto com a data;
- `historico()` agora recebe filtros (`cidade_slug`, `de`, `ate`) e devolve um `LengthAwarePaginator`;
- `cidadesConsultadas()` é novo: lista os nomes distintos, em ordem alfabética.

```php
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
```

### `app/Services/Clima/ClimaService.php`

Mudanças:
- `historico()` recebe os parâmetros validados, normaliza a cidade (slug) e as datas (fuso da aplicação; o `ate` vai até o fim do minuto), monta a chave de cache **com a versão atual** e guarda o resultado do paginator como array;
- `cidades()` é novo e usa o mesmo cache versionado;
- `invalidarCacheHistorico()` incrementa a versão (substitui o antigo `limparCacheHistorico`);
- a chamada a `atualizarDataConsulta()` agora passa `$dados`.

```php
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
```

### `app/Http/Requests/Clima/HistoricoClimaRequest.php`

Valida os parâmetros novos. `de` e `ate` aceitam qualquer data que o PHP entenda, incluindo ISO com fuso. `ate` precisa ser ≥ `de`, e `per_page` vai de 1 a 50.

```php
<?php

namespace App\Http\Requests\Clima;

use Illuminate\Foundation\Http\FormRequest;

class HistoricoClimaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'cidade' => ['nullable', 'string', 'max:100'],
            'de' => ['nullable', 'date'],
            'ate' => ['nullable', 'date', 'after_or_equal:de'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }

    public function messages(): array
    {
        return [
            'de.date' => 'A data inicial (de) é inválida.',
            'ate.date' => 'A data final (ate) é inválida.',
            'ate.after_or_equal' => 'A data final precisa ser igual ou posterior à data inicial.',
            'page.min' => 'A página precisa ser maior ou igual a 1.',
            'per_page.max' => 'São permitidos no máximo :max registros por página.',
        ];
    }
}
```

### `app/Http/Controllers/Clima/ClimaController.php`

`historico` repassa todos os parâmetros validados e devolve o paginator direto (sem embrulhar em `data`, porque o paginator já tem `data`). `cidades` é novo.

```php
<?php

namespace App\Http\Controllers\Clima;

use App\Http\Requests\Clima\HistoricoClimaRequest;
use App\Http\Requests\Clima\RegistrarClimaRequest;
use App\Services\Clima\ClimaService;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;

class ClimaController extends Controller
{
    public function __construct(private readonly ClimaService $service) {}

    public function store(RegistrarClimaRequest $request): JsonResponse
    {
        $resultado = $this->service->registrarConsulta($request->validated('cidade'));

        return response()->json([
            'data' => $resultado->consulta,
            'atualizado' => $resultado->atualizado,
        ], $resultado->atualizado ? 200 : 201);
    }

    public function historico(HistoricoClimaRequest $request): JsonResponse
    {
        return response()->json($this->service->historico($request->validated()));
    }

    public function cidades(): JsonResponse
    {
        return response()->json(['data' => $this->service->cidades()]);
    }
}
```

### `routes/api.php`

Rota nova `GET /api/clima/cidades`.

```php
<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Clima\ClimaController;
use App\Http\Controllers\Municipio\MunicipioController;

Route::get('/municipios', [MunicipioController::class, 'index']);

Route::post('/clima', [ClimaController::class, 'store']);
Route::get('/clima/historico', [ClimaController::class, 'historico']);
Route::get('/clima/cidades', [ClimaController::class, 'cidades']);
```

### Testes do backend

### `tests/Unit/ClimaServiceTest.php`

Reescrito para a nova API do service. O repository retorna um `LengthAwarePaginator` montado no próprio teste. Cobre:
- criar ou só atualizar (com o vento);
- vento diferente **não** gera registro novo;
- cada combinação de filtros com o seu cache;
- normalização de slug e de fuso;
- arrays no cache;
- cache das cidades;
- incremento da versão após gravar.

```php
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
```

### `tests/Feature/ClimaApiTest.php (helpers)`

**1)** Adicione `use Illuminate\Support\Str;` aos imports e **substitua** o método `respostaOpenWeather()` por estes dois métodos. Agora a resposta falsa tem `wind`, e há um helper para criar registros direto no banco.

```php
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
```

### `tests/Feature/ClimaApiTest.php (testes novos)`

**2)** Adicione estes testes ao final da classe. Eles cobrem:
- velocidade do vento salva, atualizada e nula quando a OpenWeather não envia;
- paginação (padrão de 10 por página);
- filtro por período, com o minuto final incluído e a conversão de fuso;
- filtro de cidade combinado com período;
- 422 para parâmetros inválidos;
- o endpoint de cidades.

```php
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
```

## Parte B: Setup do front-end

### Dependências

```bash
npm install react react-dom
npm install -D @vitejs/plugin-react typescript @types/react @types/react-dom
```

Na validação foram instaladas `react`/`react-dom` 19.3, `@vitejs/plugin-react` 6.1, `typescript` 7.0 e `@types/react`/`@types/react-dom` 19.3.

Apague o `resources/js/app.js`, que estava vazio. O ponto de entrada passa a ser `resources/js/main.tsx`.

### `vite.config.js`

Adiciona o plugin do React e troca a entrada para `main.tsx`. Saem o `bunny`/Instrument Sans, porque as fontes do design (Inter, Plus Jakarta Sans e Material Symbols) vêm do Google Fonts na view.

```js
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/main.tsx'],
            refresh: true,
        }),
        react(),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
```

### `tsconfig.json`

Configuração do TypeScript para checagem de tipos (`npx tsc --noEmit`). Quem compila é o Vite; o `tsc` só verifica.

```json
{
    "compilerOptions": {
        "target": "ES2022",
        "lib": ["ES2022", "DOM", "DOM.Iterable"],
        "module": "ESNext",
        "moduleResolution": "bundler",
        "jsx": "react-jsx",
        "strict": true,
        "noEmit": true,
        "skipLibCheck": true,
        "isolatedModules": true,
        "noUnusedLocals": true,
        "noUnusedParameters": true,
        "types": ["vite/client"]
    },
    "include": ["resources/js"]
}
```

### `resources/css/app.css`

Tailwind v4: os tokens do `DESIGN.md` e da tela viram classes (`bg-primary`, `text-primary-dark`, `border-border-tint`, `font-display`…). As regras de `.material-symbols-outlined` controlam o ícone vazado ou preenchido (`preenchido`), como no catálogo.

```css
@import 'tailwindcss';

@source '../../vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php';
@source '../../storage/framework/views/*.php';

@theme {
    --font-sans: 'Inter', ui-sans-serif, system-ui, sans-serif;
    --font-display: 'Plus Jakarta Sans', 'Inter', sans-serif;

    --color-primary: #007bb3;
    --color-primary-strong: #00608f;
    --color-primary-hover: #004c73;
    --color-primary-dark: #005b80;
    --color-brand-deep: #003e53;
    --color-brand-night: #00283c;
    --color-brand-accent: #00a1da;

    --color-surface-page: #f3f4f6;
    --color-surface-canvas: #f8fafc;
    --color-surface-subtle: #f1f5f9;
    --color-surface-tint: #f1f6fb;

    --color-border-subtle: #e2e8f0;
    --color-border-strong: #cbd5e1;
    --color-border-tint: #d5e2ee;

    --color-text-muted: #64748b;
    --color-on-surface: #111c2d;
}

.material-symbols-outlined {
    font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24;
    user-select: none;
}

.material-symbols-outlined.preenchido {
    font-variation-settings: 'FILL' 1, 'wght' 500, 'GRAD' 0, 'opsz' 24;
}
```

### `resources/views/app.blade.php`

Página única que carrega as fontes e monta o React no `#app`. O `@viteReactRefresh` habilita o hot reload do React no `npm run dev`.

```blade
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WeatherApp — Consulta meteorológica</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@600;700;800&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200&display=block" rel="stylesheet">

    @viteReactRefresh
    @vite(['resources/css/app.css', 'resources/js/main.tsx'])
</head>
<body class="bg-surface-page text-on-surface font-sans antialiased">
    <div id="app"></div>
</body>
</html>
```

### `routes/web.php`

A rota `/` passa a servir a view `app` (a `welcome.blade.php` pode ser apagada).

```php
<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'app');
```

### `tests/Feature/PaginaInicialTest.php`

**Apague o `tests/Feature/ExampleTest.php`** e crie este teste no lugar. O `ExampleTest` faz `GET /`, que agora renderiza a view com `@vite`. Sem ter rodado `npm run build`, ele quebra com `ViteManifestNotFoundException`; isso aconteceu na validação. O `withoutVite()` tira essa dependência: `php artisan test` passa com ou sem os assets compilados.

```php
<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PaginaInicialTest extends TestCase
{
    #[Test]
    public function pagina_inicial_carrega_o_app_react(): void
    {
        $this->withoutVite();

        $this->get('/')
            ->assertOk()
            ->assertSee('<div id="app"></div>', false)
            ->assertSee('WeatherApp');
    }
}
```

## Parte C: Código React (`resources/js/`)

### Tipos e acesso à API

### `resources/js/types/clima.ts`

Tipos que espelham o JSON da API. `FiltrosHistorico` guarda os valores **como digitados** na tela, e a conversão para ISO acontece na hora da requisição.

```ts
export interface ConsultaClima {
    id: number;
    cidade: string;
    temperatura: number;
    sensacao_termica: number;
    umidade: number;
    descricao: string;
    vento_kmh: number | null;
    consultado_em: string;
}

export interface Municipio {
    id: number;
    nome: string;
    uf: string;
}

export interface Paginado<T> {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
}

export interface ResultadoRegistro {
    data: ConsultaClima;
    atualizado: boolean;
}

/** Valores dos filtros como digitados na tela. `de` e `ate` vêm do <input type="datetime-local">. */
export interface FiltrosHistorico {
    cidade: string;
    de: string;
    ate: string;
}

export const FILTROS_VAZIOS: FiltrosHistorico = { cidade: '', de: '', ate: '' };
```

### `resources/js/api/client.ts`

`fetch` central:
- monta a query string, ignorando valores vazios;
- envia `Accept: application/json`, para o Laravel responder erros em JSON;
- aceita `AbortSignal`;
- transforma erros em `ApiError` com mensagem amigável: `erro` (404, 502, 503), a primeira mensagem de validação (422) ou falha de rede.

```ts
export class ApiError extends Error {
    constructor(
        message: string,
        public readonly status: number,
    ) {
        super(message);
        this.name = 'ApiError';
    }
}

type Query = Record<string, string | number | null | undefined>;

interface OpcoesRequisicao {
    query?: Query;
    signal?: AbortSignal;
}

export function foiCancelada(erro: unknown): boolean {
    return erro instanceof DOMException && erro.name === 'AbortError';
}

function montarUrl(caminho: string, query: Query = {}): string {
    const parametros = new URLSearchParams();

    for (const [chave, valor] of Object.entries(query)) {
        if (valor !== null && valor !== undefined && valor !== '') {
            parametros.set(chave, String(valor));
        }
    }

    const queryString = parametros.toString();

    return `/api${caminho}${queryString ? `?${queryString}` : ''}`;
}

async function extrairMensagemDeErro(resposta: Response): Promise<string> {
    try {
        const corpo = await resposta.json();

        if (typeof corpo?.erro === 'string') {
            return corpo.erro;
        }

        if (corpo?.errors) {
            const [primeiraLista] = Object.values(corpo.errors as Record<string, string[]>);

            if (primeiraLista?.[0]) {
                return primeiraLista[0];
            }
        }

        if (typeof corpo?.message === 'string' && corpo.message !== '') {
            return corpo.message;
        }
    } catch {
        // A resposta de erro não veio em JSON; usa a mensagem genérica abaixo.
    }

    return `Erro inesperado no servidor (HTTP ${resposta.status}).`;
}

export async function requisitar<T>(metodo: 'GET' | 'POST', caminho: string, opcoes: OpcoesRequisicao = {}): Promise<T> {
    let resposta: Response;

    try {
        resposta = await fetch(montarUrl(caminho, opcoes.query), {
            method: metodo,
            headers: { Accept: 'application/json' },
            signal: opcoes.signal,
        });
    } catch (erro) {
        if (foiCancelada(erro)) {
            throw erro;
        }

        throw new ApiError('Não foi possível conectar ao servidor. Verifique sua conexão.', 0);
    }

    if (!resposta.ok) {
        throw new ApiError(await extrairMensagemDeErro(resposta), resposta.status);
    }

    return (await resposta.json()) as T;
}
```

### `resources/js/api/municipios.ts`

Autocomplete: `GET /api/municipios?busca=`.

```ts
import type { Municipio } from '../types/clima';
import { requisitar } from './client';

export async function buscarMunicipios(termo: string, signal?: AbortSignal): Promise<Municipio[]> {
    const resposta = await requisitar<{ data: Municipio[] }>('GET', '/municipios', {
        query: { busca: termo },
        signal,
    });

    return resposta.data;
}
```

### `resources/js/api/clima.ts`

Consulta (POST), histórico paginado e cidades consultadas.

```ts
import type { ConsultaClima, Paginado, ResultadoRegistro } from '../types/clima';
import { requisitar } from './client';

export interface ParametrosHistorico {
    cidade?: string;
    /** Data/hora em ISO 8601 (com fuso), ex.: 2026-09-28T13:17:00.000Z */
    de?: string;
    ate?: string;
    page?: number;
    per_page?: number;
}

export function registrarConsulta(cidade: string, signal?: AbortSignal): Promise<ResultadoRegistro> {
    return requisitar<ResultadoRegistro>('POST', '/clima', { query: { cidade }, signal });
}

export function buscarHistorico(parametros: ParametrosHistorico, signal?: AbortSignal): Promise<Paginado<ConsultaClima>> {
    return requisitar<Paginado<ConsultaClima>>('GET', '/clima/historico', {
        query: { ...parametros },
        signal,
    });
}

export async function buscarCidadesConsultadas(signal?: AbortSignal): Promise<string[]> {
    const resposta = await requisitar<{ data: string[] }>('GET', '/clima/cidades', { signal });

    return resposta.data;
}
```

### Utilitários

### `resources/js/utils/formatadores.ts`

Formatação pt-BR:
- `23,1 °C`;
- `28/09/2026` e `10:17`;
- conversão `datetime-local` → ISO em UTC;
- classificação da umidade: abaixo de 40%, "(Baixa)"; acima de 70%, "(Alta)". Os limites ficam em constantes, se quiser ajustar.

```ts
const numero = (casas: number) =>
    new Intl.NumberFormat('pt-BR', { minimumFractionDigits: casas, maximumFractionDigits: casas });

export function formatarNumero(valor: number, casas = 1): string {
    return numero(casas).format(valor);
}

export function formatarTemperatura(valor: number): string {
    return `${formatarNumero(valor)} °C`;
}

export function formatarData(iso: string): string {
    return new Date(iso).toLocaleDateString('pt-BR');
}

export function formatarHora(iso: string): string {
    return new Date(iso).toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
}

export function formatarDiaMesHora(iso: string): string {
    const data = new Date(iso);

    return `${data.toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit' })} ${formatarHora(iso)}`;
}

/** Converte o valor de um <input type="datetime-local"> (horário local do navegador) para ISO 8601 em UTC. */
export function localParaIso(valor: string): string | undefined {
    return valor === '' ? undefined : new Date(valor).toISOString();
}

export function capitalizar(texto: string): string {
    return texto.charAt(0).toLocaleUpperCase('pt-BR') + texto.slice(1);
}

export const LIMITE_UMIDADE_BAIXA = 40;
export const LIMITE_UMIDADE_ALTA = 70;

export function classificarUmidade(umidade: number): { rotulo: string; classe: string } | null {
    if (umidade < LIMITE_UMIDADE_BAIXA) {
        return { rotulo: 'Baixa', classe: 'text-amber-600' };
    }

    if (umidade > LIMITE_UMIDADE_ALTA) {
        return { rotulo: 'Alta', classe: 'text-blue-600' };
    }

    return null;
}
```

### `resources/js/utils/iconeClima.ts`

Mapeia a descrição da OpenWeather (pt_br) para ícone, cor e fundo, conforme o catálogo. A **ordem das regras importa**: "chuva forte" antes de "chuva", "parcialmente nublado" antes de "nublado".

```ts
export interface VisualClima {
    icone: string;
    preenchido: boolean;
    corIcone: string;
    fundo: string;
    borda: string;
}

interface Regra {
    termos: string[];
    visual: VisualClima;
}

/**
 * Ordem importa: a primeira regra cujo termo aparecer na descrição vence.
 * Por isso "chuva forte" vem antes de "chuva" e "parcialmente nublado" antes de "nublado".
 * As descrições são as da OpenWeather com lang=pt_br, comparadas sem acento e em minúsculas.
 */
const REGRAS: Regra[] = [
    {
        termos: ['trovoada', 'tempestade', 'raio'],
        visual: { icone: 'thunderstorm', preenchido: true, corIcone: 'text-indigo-600', fundo: 'bg-indigo-50', borda: 'border-indigo-200' },
    },
    {
        termos: ['chuva forte', 'chuva muito forte', 'chuva extrema', 'chuva intensa', 'aguaceiro'],
        visual: { icone: 'rainy_heavy', preenchido: true, corIcone: 'text-primary-dark', fundo: 'bg-blue-100', borda: 'border-blue-200' },
    },
    {
        termos: ['chuva', 'garoa', 'chuvisco'],
        visual: { icone: 'rainy', preenchido: true, corIcone: 'text-blue-500', fundo: 'bg-blue-50', borda: 'border-blue-200' },
    },
    {
        termos: ['nevoa', 'neblina', 'nevoeiro', 'bruma', 'fumaca', 'poeira', 'areia', 'cinza'],
        visual: { icone: 'foggy', preenchido: false, corIcone: 'text-slate-600', fundo: 'bg-slate-100', borda: 'border-slate-200' },
    },
    {
        termos: ['vento', 'rajada', 'ventania', 'tornado'],
        visual: { icone: 'air', preenchido: false, corIcone: 'text-teal-600', fundo: 'bg-teal-50', borda: 'border-teal-200' },
    },
    {
        termos: ['algumas nuvens', 'poucas nuvens'],
        visual: { icone: 'filter_drama', preenchido: true, corIcone: 'text-amber-600', fundo: 'bg-amber-50', borda: 'border-amber-200' },
    },
    {
        termos: ['nuvens dispersas', 'parcialmente nublado'],
        visual: { icone: 'partly_cloudy_day', preenchido: true, corIcone: 'text-sky-500', fundo: 'bg-sky-50', borda: 'border-sky-200' },
    },
    {
        termos: ['nublado', 'encoberto', 'nuvens'],
        visual: { icone: 'cloud', preenchido: true, corIcone: 'text-slate-500', fundo: 'bg-slate-100', borda: 'border-slate-200' },
    },
    {
        termos: ['ceu limpo', 'limpo', 'ensolarado', 'sol'],
        visual: { icone: 'wb_sunny', preenchido: true, corIcone: 'text-amber-500', fundo: 'bg-amber-50', borda: 'border-amber-200' },
    },
];

const VISUAL_PADRAO: VisualClima = {
    icone: 'device_thermostat',
    preenchido: false,
    corIcone: 'text-primary',
    fundo: 'bg-white',
    borda: 'border-border-tint',
};

function normalizar(texto: string): string {
    return texto
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase();
}

export function visualDoClima(descricao: string): VisualClima {
    const texto = normalizar(descricao);
    const regra = REGRAS.find(({ termos }) => termos.some((termo) => texto.includes(termo)));

    return regra?.visual ?? VISUAL_PADRAO;
}
```

### `resources/js/utils/estatisticas.ts`

Mínima, média e máxima das consultas, mais a ordenação cronológica para a curva. A API devolve do mais recente para o mais antigo.

```ts
import type { ConsultaClima } from '../types/clima';

export interface EstatisticasTemperatura {
    minima: ConsultaClima;
    maxima: ConsultaClima;
    media: number;
    quantidade: number;
}

export function calcularEstatisticas(consultas: ConsultaClima[]): EstatisticasTemperatura | null {
    if (consultas.length === 0) {
        return null;
    }

    let minima = consultas[0];
    let maxima = consultas[0];
    let soma = 0;

    for (const consulta of consultas) {
        if (consulta.temperatura < minima.temperatura) {
            minima = consulta;
        }

        if (consulta.temperatura > maxima.temperatura) {
            maxima = consulta;
        }

        soma += consulta.temperatura;
    }

    return { minima, maxima, media: soma / consultas.length, quantidade: consultas.length };
}

/** A API devolve do mais recente para o mais antigo; o gráfico precisa da ordem cronológica. */
export function emOrdemCronologica(consultas: ConsultaClima[]): ConsultaClima[] {
    return [...consultas].sort(
        (a, b) => new Date(a.consultado_em).getTime() - new Date(b.consultado_em).getTime() || a.id - b.id,
    );
}
```

### Hooks

### `resources/js/hooks/useDebounce.ts`

Atrasa um valor, para não chamar a API a cada tecla.

```ts
import { useEffect, useState } from 'react';

export function useDebounce<T>(valor: T, atrasoMs = 300): T {
    const [valorAtrasado, setValorAtrasado] = useState(valor);

    useEffect(() => {
        const temporizador = window.setTimeout(() => setValorAtrasado(valor), atrasoMs);

        return () => window.clearTimeout(temporizador);
    }, [valor, atrasoMs]);

    return valorAtrasado;
}
```

### `resources/js/hooks/useRequisicao.ts`

Hook base: busca quando as dependências mudam, **cancela a requisição anterior** (evita resposta velha sobrescrevendo a nova) e mantém os dados antigos enquanto carrega, para a tabela não piscar ao paginar.

```ts
import { type DependencyList, useEffect, useState } from 'react';
import { foiCancelada } from '../api/client';

export interface EstadoRequisicao<T> {
    dados: T | null;
    carregando: boolean;
    erro: string | null;
}

/**
 * Executa uma requisição sempre que as dependências mudam e cancela a anterior.
 * Os dados antigos são mantidos durante o carregamento (evita a tabela "piscar" ao paginar).
 * Passe `null` em `executar` para não buscar nada.
 */
export function useRequisicao<T>(
    executar: ((signal: AbortSignal) => Promise<T>) | null,
    dependencias: DependencyList,
): EstadoRequisicao<T> {
    const [estado, setEstado] = useState<EstadoRequisicao<T>>({
        dados: null,
        carregando: executar !== null,
        erro: null,
    });

    useEffect(() => {
        if (executar === null) {
            setEstado({ dados: null, carregando: false, erro: null });

            return;
        }

        const controle = new AbortController();
        setEstado((atual) => ({ ...atual, carregando: true, erro: null }));

        executar(controle.signal)
            .then((dados) => setEstado({ dados, carregando: false, erro: null }))
            .catch((erro: unknown) => {
                if (foiCancelada(erro)) {
                    return;
                }

                setEstado((atual) => ({
                    ...atual,
                    carregando: false,
                    erro: erro instanceof Error ? erro.message : 'Erro inesperado.',
                }));
            });

        return () => controle.abort();
    }, dependencias);

    return estado;
}
```

### `resources/js/hooks/useAutocomplete.ts`

Sugestões de municípios com debounce de 300 ms e mínimo de 2 caracteres.

```ts
import { buscarMunicipios } from '../api/municipios';
import type { Municipio } from '../types/clima';
import { useDebounce } from './useDebounce';
import { useRequisicao } from './useRequisicao';

export const MINIMO_CARACTERES_BUSCA = 2;

export function useAutocomplete(termo: string, ativo: boolean): { sugestoes: Municipio[]; carregando: boolean } {
    const termoAtrasado = useDebounce(termo.trim(), 300);
    const deveBuscar = ativo && termoAtrasado.length >= MINIMO_CARACTERES_BUSCA;

    const { dados, carregando } = useRequisicao(
        deveBuscar ? (signal) => buscarMunicipios(termoAtrasado, signal) : null,
        [termoAtrasado, deveBuscar],
    );

    return { sugestoes: deveBuscar ? (dados ?? []) : [], carregando: deveBuscar && carregando };
}
```

### `resources/js/hooks/useHistorico.ts`

Três hooks:
- `useHistorico`: a página da tabela;
- `useConsultasDaCidade`: até 50 consultas da cidade selecionada, para o card;
- `useCidadesConsultadas`: as opções do select.

O parâmetro `versao` é um contador: incrementá-lo força uma nova busca (Recarregar ou depois de uma consulta).

```ts
import { buscarCidadesConsultadas, buscarHistorico } from '../api/clima';
import type { ConsultaClima, FiltrosHistorico } from '../types/clima';
import { localParaIso } from '../utils/formatadores';
import { useRequisicao } from './useRequisicao';

export const POR_PAGINA = 10;
export const MAXIMO_CONSULTAS_NO_CARD = 50;

/** `versao` é um contador: incrementá-lo força uma nova busca (botão Recarregar, nova consulta). */
export function useHistorico(filtros: FiltrosHistorico, pagina: number, versao: number) {
    return useRequisicao(
        (signal) =>
            buscarHistorico(
                {
                    cidade: filtros.cidade,
                    de: localParaIso(filtros.de),
                    ate: localParaIso(filtros.ate),
                    page: pagina,
                    per_page: POR_PAGINA,
                },
                signal,
            ),
        [filtros, pagina, versao],
    );
}

/** Consultas da cidade selecionada (respeitando o período filtrado), usadas na curva e nas estatísticas do card. */
export function useConsultasDaCidade(cidade: string | null, filtros: FiltrosHistorico, versao: number) {
    const { dados, carregando } = useRequisicao<ConsultaClima[]>(
        cidade === null
            ? null
            : async (signal) => {
                  const pagina = await buscarHistorico(
                      {
                          cidade,
                          de: localParaIso(filtros.de),
                          ate: localParaIso(filtros.ate),
                          per_page: MAXIMO_CONSULTAS_NO_CARD,
                      },
                      signal,
                  );

                  return pagina.data;
              },
        [cidade, filtros.de, filtros.ate, versao],
    );

    return { consultas: dados ?? [], carregando };
}

export function useCidadesConsultadas(versao: number): string[] {
    const { dados } = useRequisicao((signal) => buscarCidadesConsultadas(signal), [versao]);

    return dados ?? [];
}
```

### Componentes

### `resources/js/components/Icone.tsx`

Atalho para os ícones do Material Symbols, com a variante preenchida.

```tsx
interface IconeProps {
    nome: string;
    className?: string;
    preenchido?: boolean;
}

/** Ícone do Material Symbols Outlined (a fonte é carregada em resources/views/app.blade.php). */
export function Icone({ nome, className = '', preenchido = false }: IconeProps) {
    return (
        <span aria-hidden="true" className={`material-symbols-outlined ${preenchido ? 'preenchido' : ''} ${className}`}>
            {nome}
        </span>
    );
}
```

### `resources/js/components/LogoLoop.tsx`

Logotipo da Loop Sistemas, copiado do SVG da tela de referência. As classes `.st0`–`.st8` viraram `fill` direto em cada forma.

```tsx
export function LogoLoop({ className = '' }: { className?: string }) {
    return (
        <svg className={className} viewBox="0 0 695.8 347.3" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Loop Sistemas">
            <polygon fill="#00A1DA" points="242.5,72.9 268.7,46.7 268.7,231.7 242.5,231.7 " />
            <path fill="#007BB3" d="M341.5,205.2c5,0,9.8-1,14.4-2.9c4.5-1.9,8.4-4.5,11.7-7.8c3.3-3.3,5.9-7.1,7.8-11.6c1.9-4.5,2.9-9.2,2.9-14.2 s-1-9.8-2.9-14.4c-1.9-4.5-4.5-8.4-7.8-11.7c-3.3-3.3-7.2-5.9-11.7-7.8c-4.5-1.9-9.3-2.9-14.4-2.9c-5,0-9.8,1-14.4,2.9 c-4.5,1.9-8.5,4.5-11.8,7.8c-3.4,3.3-6,7.2-7.9,11.7c-1.9,4.5-2.9,9.3-2.9,14.4s1,9.8,2.9,14.2c1.9,4.5,4.6,8.3,7.9,11.6 c3.4,3.3,7.3,5.9,11.8,7.8C331.7,204.2,336.5,205.2,341.5,205.2 M341.5,105.7c8.6,0,16.7,1.6,24.3,4.9c7.6,3.3,14.3,7.8,20,13.5 c5.7,5.7,10.2,12.4,13.6,20c3.4,7.6,5,15.8,5,24.6c0,8.7-1.7,16.9-5,24.6c-3.4,7.6-7.9,14.3-13.6,19.9c-5.7,5.6-12.4,10.1-20,13.4 c-7.6,3.3-15.7,4.9-24.3,4.9c-8.7,0-16.9-1.6-24.6-4.9c-7.6-3.3-14.3-7.7-19.9-13.4c-5.6-5.6-10.1-12.3-13.4-19.9 c-3.3-7.6-4.9-15.8-4.9-24.6c0-8.7,1.6-16.9,4.9-24.6c3.3-7.6,7.7-14.3,13.4-20c5.6-5.7,12.3-10.2,19.9-13.5 C324.6,107.3,332.8,105.7,341.5,105.7z" />
            <path fill="#005B80" d="M477.3,205.2c5,0,9.8-1,14.4-2.9c4.5-1.9,8.4-4.5,11.7-7.8c3.3-3.3,5.9-7.1,7.8-11.6c1.9-4.5,2.9-9.2,2.9-14.2 s-1-9.8-2.9-14.4c-1.9-4.5-4.5-8.4-7.8-11.7c-3.3-3.3-7.2-5.9-11.7-7.8c-4.5-1.9-9.3-2.9-14.4-2.9c-5,0-9.8,1-14.4,2.9 c-4.5,1.9-8.5,4.5-11.8,7.8c-3.4,3.3-6,7.2-7.9,11.7c-1.9,4.5-2.9,9.3-2.9,14.4s1,9.8,2.9,14.2c1.9,4.5,4.6,8.3,7.9,11.6 c3.4,3.3,7.3,5.9,11.8,7.8C467.5,204.2,472.3,205.2,477.3,205.2 M477.3,105.7c8.6,0,16.7,1.6,24.3,4.9c7.6,3.3,14.3,7.8,20,13.5 c5.7,5.7,10.2,12.4,13.6,20c3.4,7.6,5,15.8,5,24.6c0,8.7-1.7,16.9-5,24.6c-3.4,7.6-7.9,14.3-13.6,19.9c-5.7,5.6-12.4,10.1-20,13.4 c-7.6,3.3-15.7,4.9-24.3,4.9c-8.7,0-16.9-1.6-24.6-4.9c-7.6-3.3-14.3-7.7-19.9-13.4c-5.6-5.6-10.1-12.3-13.4-19.9 c-3.3-7.6-4.9-15.8-4.9-24.6c0-8.7,1.6-16.9,4.9-24.6c3.3-7.6,7.7-14.3,13.4-20c5.6-5.7,12.3-10.2,19.9-13.5 C460.4,107.3,468.6,105.7,477.3,105.7z" />
            <path fill="#003E53" d="M613.2,231.7c-5.5,0-10.7-0.6-15.4-1.8l6.3-25.5c2.7,1,5.7,1.5,9.1,1.5c5,0,9.8-1,14.4-2.9 c4.5-1.9,8.5-4.6,11.8-7.9c3.4-3.4,6-7.3,7.9-11.8c1.9-4.5,2.9-9.4,2.9-14.6c0-5-1-9.8-2.9-14.2c-1.9-4.5-4.6-8.4-7.9-11.7 c-3.4-3.4-7.3-6-11.8-7.9c-4.5-1.9-9.3-2.9-14.4-2.9c-5,0-9.8,1-14.4,2.9c-4.5,1.9-8.5,4.6-11.8,7.9c-3.4,3.4-6,7.3-7.9,11.7 c-1.9,4.5-2.9,9.2-2.9,14.2v0.5v68.3v52.9l-25.7-26v-68.5v-26.7v-0.5c0-8.6,1.6-16.7,4.9-24.3c3.3-7.6,7.7-14.3,13.4-20 c5.6-5.7,12.3-10.2,19.9-13.6c7.6-3.4,15.8-5,24.6-5c8.6,0,16.7,1.7,24.4,5c7.7,3.4,14.4,7.9,20.2,13.6c5.7,5.7,10.2,12.4,13.6,20 c3.4,7.6,5,15.7,5,24.3c0,8.7-1.7,16.9-5,24.6c-3.4,7.6-7.9,14.3-13.6,20c-5.7,5.7-12.4,10.2-20.2,13.5 C629.9,230,621.7,231.7,613.2,231.7" />
            <path fill="#656261" d="M527.7,257.6c1.9,0.6,3.6,1.3,5.1,1.9c1.5,0.7,2.8,1.4,3.9,2.4c1.1,0.9,1.9,2,2.5,3.3c0.6,1.3,0.9,2.9,0.9,4.7 c0,2-0.4,3.8-1.3,5.4c-0.8,1.6-2.1,3-3.7,4.1c-1.6,1.1-3.6,2-6,2.6c-2.4,0.6-5.1,0.9-8.1,0.9c-2.8,0-5.3-0.2-7.4-0.6 c-2.1-0.4-4.4-1-6.9-1.7c0.2-1.3,0.5-2.7,0.9-4.2c0.5-1.5,1-3,1.6-4.5c4.1,1.5,7.7,2.2,10.8,2.2c2.3,0,4.1-0.3,5.4-0.9 c1.3-0.6,1.9-1.6,1.9-3c0-0.6-0.1-1.1-0.4-1.5c-0.3-0.4-0.6-0.8-1.1-1.1c-0.5-0.3-1-0.6-1.5-0.8c-0.6-0.2-1.1-0.4-1.7-0.6l-3.8-1.2 c-1.6-0.5-3.1-1.1-4.4-1.7c-1.3-0.7-2.5-1.5-3.4-2.4c-0.9-0.9-1.7-2-2.2-3.2c-0.5-1.2-0.8-2.6-0.8-4.2c0-2,0.4-3.7,1.2-5.3 c0.8-1.5,1.9-2.8,3.4-3.9c1.5-1.1,3.2-1.9,5.3-2.4c2.1-0.5,4.4-0.8,7-0.8c2.3,0,4.5,0.2,6.7,0.6c2.1,0.4,4.4,0.9,6.9,1.6 c-0.2,1.2-0.5,2.5-1,4c-0.5,1.4-1.1,2.8-1.8,4.1c-1.2-0.4-2.5-0.8-4.1-1.3c-1.6-0.4-3.5-0.7-5.9-0.7c-0.7,0-1.4,0.1-2.1,0.2 c-0.7,0.1-1.2,0.3-1.8,0.5c-0.5,0.2-0.9,0.6-1.3,1c-0.3,0.4-0.5,1-0.5,1.6c0,1.1,0.4,1.9,1.3,2.4c0.9,0.5,1.9,1,3,1.3L527.7,257.6z M482.8,252.5l-4.5,14.5c0.8,0.2,1.5,0.3,2.2,0.4c0.7,0.1,1.4,0.2,2.1,0.2c0.8,0,1.5,0,2.3-0.1c0.7-0.1,1.5-0.2,2.3-0.4L482.8,252.5 z M473.3,282.2c-1,0.1-2,0.3-2.8,0.3c-0.9,0.1-1.7,0.1-2.5,0.1c-0.8,0-1.7,0-2.8-0.1c-1.1-0.1-2.2-0.2-3.5-0.4l15.1-40.2 c1.2-0.1,2.3-0.2,3.3-0.3c1-0.1,2-0.1,3.1-0.1c0.9,0,1.8,0,2.8,0.1c1,0.1,2.2,0.2,3.5,0.3l14.9,40.2c-2.6,0.3-4.8,0.5-6.8,0.5 c-0.9,0-1.8,0-2.7-0.1c-0.9-0.1-1.9-0.2-2.9-0.3l-2.6-7.8c-1.3,0.3-2.5,0.5-3.6,0.7c-1.1,0.2-2.3,0.2-3.4,0.2c-1,0-2.1-0.1-3.2-0.2 c-1.1-0.1-2.2-0.3-3.4-0.6L473.3,282.2z M412.7,242c0.9-0.1,2-0.2,3.1-0.3c1.1-0.1,2.1-0.1,3.1-0.1c0.9,0,1.9,0,2.9,0.1 c1.1,0.1,2,0.2,2.9,0.3l9.6,20.9l9.9-20.9c0.9-0.1,1.8-0.2,2.7-0.3c0.9-0.1,1.8-0.1,2.7-0.1c0.9,0,1.9,0,2.9,0.1 c1,0.1,2,0.2,2.9,0.3l2.3,40.2c-0.7,0.1-1.5,0.2-2.4,0.3c-0.9,0.1-2,0.2-3.5,0.2c-1.6,0-3.4-0.2-5.6-0.5l-0.9-23.3l-7.4,15.4 c-0.8,0.1-1.6,0.2-2.4,0.3c-0.8,0.1-1.6,0.1-2.2,0.1c-0.7,0-1.4,0-2.1-0.1c-0.7-0.1-1.5-0.2-2.2-0.3l-6.6-15.1l-0.7,23.1 c-1.9,0.3-3.7,0.5-5.4,0.5c-1.6,0-3.4-0.2-5.5-0.5L412.7,242z M401.3,261.2c0,1.3-0.2,2.8-0.6,4.4h-13v8h17c0.4,1.6,0.6,3,0.6,4.3 c0,1.4-0.2,2.9-0.6,4.4h-28.9v-40.4h28.5c0.2,0.8,0.3,1.6,0.4,2.3c0.1,0.7,0.1,1.4,0.1,2c0,1.2-0.2,2.7-0.5,4.4h-16.6v6.2h13 C401.1,258.5,401.3,259.9,401.3,261.2z M346.8,250.7h-11.3c-0.4-1.6-0.6-3.1-0.6-4.4c0-1.2,0.2-2.7,0.6-4.3h34.5 c0.2,0.8,0.3,1.6,0.4,2.3c0.1,0.7,0.1,1.4,0.1,2c0,1.2-0.2,2.7-0.5,4.4h-11.3v31.5c-2.4,0.3-4.4,0.5-5.9,0.5c-1.7,0-3.7-0.2-6-0.5 V250.7z M320.5,257.6c1.9,0.6,3.6,1.3,5.1,1.9c1.5,0.7,2.8,1.4,3.9,2.4c1.1,0.9,1.9,2,2.5,3.3c0.6,1.3,0.9,2.9,0.9,4.7 c0,2-0.4,3.8-1.2,5.4c-0.8,1.6-2.1,3-3.7,4.1c-1.6,1.1-3.6,2-6,2.6c-2.4,0.6-5.1,0.9-8.1,0.9c-2.8,0-5.3-0.2-7.4-0.6 c-2.1-0.4-4.4-1-6.9-1.7c0.2-1.3,0.5-2.7,0.9-4.2c0.5-1.5,1-3,1.6-4.5c4.1,1.5,7.7,2.2,10.8,2.2c2.3,0,4.1-0.3,5.4-0.9 c1.3-0.6,1.9-1.6,1.9-3c0-0.6-0.1-1.1-0.4-1.5c-0.3-0.4-0.6-0.8-1.1-1.1c-0.5-0.3-1-0.6-1.5-0.8c-0.6-0.2-1.1-0.4-1.7-0.6l-3.8-1.2 c-1.6-0.5-3.1-1.1-4.4-1.7c-1.3-0.7-2.5-1.5-3.4-2.4c-0.9-0.9-1.7-2-2.2-3.2c-0.5-1.2-0.8-2.6-0.8-4.2c0-2,0.4-3.7,1.2-5.3 c0.8-1.5,1.9-2.8,3.4-3.9c1.5-1.1,3.2-1.9,5.3-2.4c2.1-0.5,4.4-0.8,7-0.8c2.3,0,4.5,0.2,6.7,0.6c2.1,0.4,4.4,0.9,6.9,1.6 c-0.2,1.2-0.5,2.5-1,4c-0.5,1.4-1.1,2.8-1.8,4.1c-1.2-0.4-2.5-0.8-4.1-1.3c-1.6-0.4-3.5-0.7-5.9-0.7c-0.7,0-1.4,0.1-2.1,0.2 c-0.7,0.1-1.2,0.3-1.8,0.5c-0.5,0.2-0.9,0.6-1.2,1c-0.3,0.4-0.5,1-0.5,1.6c0,1.1,0.4,1.9,1.3,2.4c0.9,0.5,1.9,1,3,1.3L320.5,257.6z M287.5,282.7c-1.7,0-3.7-0.2-6-0.5V242c1.2-0.1,2.3-0.3,3.3-0.3c1-0.1,1.9-0.1,2.6-0.1c0.8,0,1.7,0,2.7,0.1c1,0.1,2.1,0.2,3.3,0.3 v40.2C291.1,282.5,289.1,282.7,287.5,282.7z M262.3,257.6c1.9,0.6,3.6,1.3,5.1,1.9c1.5,0.7,2.8,1.4,3.9,2.4c1.1,0.9,1.9,2,2.5,3.3 c0.6,1.3,0.9,2.9,0.9,4.7c0,2-0.4,3.8-1.2,5.4c-0.8,1.6-2.1,3-3.7,4.1c-1.6,1.1-3.6,2-6,2.6c-2.4,0.6-5.1,0.9-8.1,0.9 c-2.8,0-5.3-0.2-7.4-0.6c-2.1-0.4-4.4-1-6.9-1.7c0.2-1.3,0.5-2.7,0.9-4.2c0.5-1.5,1-3,1.6-4.5c4.1,1.5,7.7,2.2,10.8,2.2 c2.3,0,4.1-0.3,5.4-0.9c1.3-0.6,1.9-1.6,1.9-3c0-0.6-0.1-1.1-0.4-1.5c-0.3-0.4-0.6-0.8-1.1-1.1c-0.5-0.3-1-0.6-1.5-0.8 c-0.6-0.2-1.1-0.4-1.7-0.6l-3.8-1.2c-1.6-0.5-3.1-1.1-4.4-1.7c-1.3-0.7-2.5-1.5-3.4-2.4s-1.7-2-2.2-3.2c-0.5-1.2-0.8-2.6-0.8-4.2 c0-2,0.4-3.7,1.2-5.3c0.8-1.5,1.9-2.8,3.4-3.9c1.5-1.1,3.2-1.9,5.3-2.4c2.1-0.5,4.4-0.8,7-0.8c2.3,0,4.5,0.2,6.7,0.6 c2.1,0.4,4.4,0.9,6.9,1.6c-0.2,1.2-0.5,2.5-1,4c-0.5,1.4-1.1,2.8-1.8,4.1c-1.2-0.4-2.5-0.8-4.1-1.3c-1.6-0.4-3.5-0.7-5.9-0.7 c-0.7,0-1.4,0.1-2.1,0.2c-0.7,0.1-1.2,0.3-1.8,0.5c-0.5,0.2-0.9,0.6-1.2,1c-0.3,0.4-0.5,1-0.5,1.6c0,1.1,0.4,1.9,1.3,2.4 c0.9,0.5,1.9,1,3,1.3L262.3,257.6z" />
            <path fill="#005B80" fillRule="evenodd" clipRule="evenodd" d="M81.4,242.4c-9.5,10.7-15.6,26.7-13.8,48.9c1.4,18.2,10.4,34.4,20.3,42.8c5.4-3.4,103.4-100.7,108.8-109.7 c24.4-40.3,2.3-85.5-18.8-94.4C184.1,153.9,99.8,221,81.4,242.4" />
            <path fill="#003E53" fillRule="evenodd" clipRule="evenodd" d="M95.7,142.7c12.5,15.7,26.8,30.5,44.1,43.4c7.9-7.2,42.9-36,45.2-51.4c-7.7-8.8-24.3-13.5-39.3-13.6 C125.3,120.9,109.3,128.2,95.7,142.7" />
            <path fill="#00A1DA" fillRule="evenodd" clipRule="evenodd" d="M133.2,94.7C142.7,84,148.8,68,147,45.8c-1.4-18.2-10.4-34.4-20.3-42.8C121.3,6.4,23.4,103.7,17.9,112.7 c-24.4,40.3-2.3,85.5,18.8,94.4C30.6,183.2,114.8,116.1,133.2,94.7" />
            <path fill="#007BB3" fillRule="evenodd" clipRule="evenodd" d="M118.9,194.4c-12.5-15.7-26.8-30.5-44.1-43.4c-7.9,7.2-42.9,36-45.2,51.4c7.7,8.8,24.3,13.5,39.3,13.6 C89.3,216.2,105.3,208.9,118.9,194.4" />
        </svg>
    );
}
```

### `resources/js/components/Header.tsx`

Cabeçalho branco com o logo centralizado.

```tsx
import { LogoLoop } from './LogoLoop';

export function Header() {
    return (
        <header className="w-full bg-white border-b border-border-strong py-4 shadow-sm flex justify-center">
            <div className="flex flex-col items-center gap-1.5">
                <LogoLoop className="h-10 w-auto" />
                <span className="text-xs text-text-muted font-medium tracking-wide">Consulta meteorológica</span>
            </div>
        </header>
    );
}
```

### `resources/js/components/Alerta.tsx`

Banner de erro (vermelho) ou aviso (azul), com botão de fechar.

```tsx
import { Icone } from './Icone';

interface AlertaProps {
    tipo: 'erro' | 'info';
    mensagem: string;
    aoFechar: () => void;
}

const ESTILOS = {
    erro: { caixa: 'bg-rose-50 border-rose-200 text-rose-800', icone: 'error', corIcone: 'text-rose-600' },
    info: { caixa: 'bg-sky-50 border-sky-200 text-sky-800', icone: 'info', corIcone: 'text-sky-600' },
};

export function Alerta({ tipo, mensagem, aoFechar }: AlertaProps) {
    const estilo = ESTILOS[tipo];

    return (
        <div role={tipo === 'erro' ? 'alert' : 'status'} className={`flex items-start gap-2 rounded-lg border px-4 py-3 text-sm ${estilo.caixa}`}>
            <Icone nome={estilo.icone} className={`text-[20px] ${estilo.corIcone}`} />
            <p className="flex-1 font-medium">{mensagem}</p>
            <button type="button" onClick={aoFechar} className="cursor-pointer opacity-70 hover:opacity-100" aria-label="Fechar aviso">
                <Icone nome="close" className="text-[18px]" />
            </button>
        </div>
    );
}
```

### `resources/js/components/BarraConsulta.tsx`

Campo de busca com autocomplete (combobox acessível, com setas ↑/↓, Enter e Esc) e os botões Limpar, Consultar e Cancelar.
- Escolher uma sugestão preenche "Jales, SP", mas só "Jales" é enviado.
- O `onMouseDown` com `preventDefault` na sugestão evita que o `onBlur` do input feche a lista antes do clique.

```tsx
import { type FormEvent, type KeyboardEvent, useId, useState } from 'react';
import { MINIMO_CARACTERES_BUSCA, useAutocomplete } from '../hooks/useAutocomplete';
import type { Municipio } from '../types/clima';
import { Icone } from './Icone';

interface BarraConsultaProps {
    consultando: boolean;
    aoConsultar: (cidade: string) => void;
    aoCancelar: () => void;
}

/** "Jales, SP" → "Jales". A API recebe só o nome da cidade. */
function extrairNomeDaCidade(texto: string): string {
    return texto.split(',')[0].trim();
}

export function BarraConsulta({ consultando, aoConsultar, aoCancelar }: BarraConsultaProps) {
    const idLista = useId();
    const [texto, setTexto] = useState('');
    const [listaAberta, setListaAberta] = useState(false);
    const [indiceAtivo, setIndiceAtivo] = useState(-1);

    const { sugestoes, carregando } = useAutocomplete(texto, listaAberta);
    const mostrarLista = listaAberta && texto.trim().length >= MINIMO_CARACTERES_BUSCA;
    const cidade = extrairNomeDaCidade(texto);

    function escolher(municipio: Municipio) {
        setTexto(`${municipio.nome}, ${municipio.uf}`);
        setListaAberta(false);
        setIndiceAtivo(-1);
    }

    function enviar(evento: FormEvent) {
        evento.preventDefault();

        if (cidade.length >= MINIMO_CARACTERES_BUSCA && !consultando) {
            setListaAberta(false);
            aoConsultar(cidade);
        }
    }

    function limpar() {
        setTexto('');
        setListaAberta(false);
        setIndiceAtivo(-1);
    }

    function navegarComTeclado(evento: KeyboardEvent<HTMLInputElement>) {
        if (!mostrarLista || sugestoes.length === 0) {
            return;
        }

        if (evento.key === 'ArrowDown') {
            evento.preventDefault();
            setIndiceAtivo((indice) => (indice + 1) % sugestoes.length);
        } else if (evento.key === 'ArrowUp') {
            evento.preventDefault();
            setIndiceAtivo((indice) => (indice <= 0 ? sugestoes.length - 1 : indice - 1));
        } else if (evento.key === 'Enter' && indiceAtivo >= 0) {
            evento.preventDefault();
            escolher(sugestoes[indiceAtivo]);
        } else if (evento.key === 'Escape') {
            setListaAberta(false);
        }
    }

    return (
        <form onSubmit={enviar} className="bg-white rounded-lg border border-border-strong p-5 shadow-sm">
            <div className="flex flex-col md:flex-row items-center gap-3 w-full">
                <div className="relative flex-1 w-full">
                    <div className="flex items-center bg-surface-tint border border-border-tint rounded-lg px-3.5 py-2 shadow-inner focus-within:border-primary focus-within:ring-3 focus-within:ring-brand-accent/20">
                        <Icone nome="search" className="text-primary text-[18px] mr-2" />
                        <input
                            type="text"
                            value={texto}
                            onChange={(evento) => {
                                setTexto(evento.target.value);
                                setListaAberta(true);
                                setIndiceAtivo(-1);
                            }}
                            onFocus={() => setListaAberta(true)}
                            onBlur={() => setListaAberta(false)}
                            onKeyDown={navegarComTeclado}
                            placeholder="Digite a cidade, ex.: Jales, SP"
                            aria-label="Cidade"
                            role="combobox"
                            aria-expanded={mostrarLista}
                            aria-controls={idLista}
                            aria-autocomplete="list"
                            autoComplete="off"
                            className="bg-transparent border-0 p-0 text-sm font-medium text-slate-800 focus:outline-none w-full placeholder:text-slate-400"
                        />
                        {texto !== '' && (
                            <button
                                type="button"
                                onClick={limpar}
                                className="flex items-center gap-1 text-[11px] font-bold text-slate-500 hover:text-slate-700 uppercase tracking-wider ml-2 cursor-pointer"
                            >
                                <Icone nome="cancel" className="text-[15px]" />
                                Limpar
                            </button>
                        )}
                    </div>

                    {mostrarLista && (
                        <ul
                            id={idLista}
                            role="listbox"
                            className="absolute z-20 mt-1 w-full max-h-72 overflow-auto bg-white border border-border-strong rounded-lg shadow-[0_12px_24px_-4px_rgba(15,23,42,0.1),0_4px_6px_-2px_rgba(15,23,42,0.05)] py-1"
                        >
                            {carregando && sugestoes.length === 0 && <li className="px-3.5 py-2 text-xs text-text-muted">Buscando cidades…</li>}
                            {!carregando && sugestoes.length === 0 && <li className="px-3.5 py-2 text-xs text-text-muted">Nenhuma cidade encontrada.</li>}
                            {sugestoes.map((municipio, indice) => (
                                <li
                                    key={municipio.id}
                                    role="option"
                                    aria-selected={indice === indiceAtivo}
                                    onMouseDown={(evento) => {
                                        evento.preventDefault();
                                        escolher(municipio);
                                    }}
                                    onMouseEnter={() => setIndiceAtivo(indice)}
                                    className={`flex items-center justify-between px-3.5 py-2 text-sm cursor-pointer ${
                                        indice === indiceAtivo ? 'bg-surface-tint text-primary-dark' : 'text-slate-700'
                                    }`}
                                >
                                    <span className="flex items-center gap-2">
                                        <Icone nome="location_on" className="text-[16px] text-primary" />
                                        {municipio.nome}
                                    </span>
                                    <span className="px-1.5 py-0.5 bg-[#E1EEF8] text-primary-dark font-bold text-[10px] rounded">{municipio.uf}</span>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>

                <div className="flex items-center gap-2 w-full md:w-auto">
                    <button
                        type="submit"
                        disabled={consultando || cidade.length < MINIMO_CARACTERES_BUSCA}
                        className="flex-1 md:flex-initial inline-flex items-center justify-center gap-2 px-5 py-2.5 bg-primary-strong hover:bg-primary-hover active:bg-brand-deep text-white text-sm font-semibold rounded-lg shadow-sm transition-colors cursor-pointer disabled:cursor-not-allowed disabled:opacity-60"
                    >
                        <Icone nome={consultando ? 'progress_activity' : 'my_location'} className={`text-[18px] ${consultando ? 'animate-spin' : ''}`} />
                        {consultando ? 'Consultando…' : 'Consultar'}
                    </button>
                    <button
                        type="button"
                        onClick={aoCancelar}
                        disabled={!consultando}
                        className="flex-1 md:flex-initial inline-flex items-center justify-center gap-1.5 px-4 py-2.5 bg-white hover:bg-slate-50 border border-slate-300 text-slate-700 text-sm font-medium rounded-lg transition-colors cursor-pointer disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        <Icone nome="disabled_by_default" className="text-[17px] text-slate-500" />
                        Cancelar
                    </button>
                </div>
            </div>
        </form>
    );
}
```

### `resources/js/components/FiltrosHistorico.tsx`

Select de cidade e campos De/Até (`datetime-local`). Os campos editam um **rascunho**; nada é buscado até clicar em Filtrar. Limpar zera e aplica na hora.

```tsx
import { type FormEvent, useEffect, useState } from 'react';
import { FILTROS_VAZIOS, type FiltrosHistorico as Filtros } from '../types/clima';
import { Icone } from './Icone';

interface FiltrosHistoricoProps {
    cidades: string[];
    filtrosAplicados: Filtros;
    aoFiltrar: (filtros: Filtros) => void;
}

const classeCampo = 'flex items-center gap-1.5 bg-slate-50 border border-slate-200 rounded px-2.5 py-1.5';

export function FiltrosHistorico({ cidades, filtrosAplicados, aoFiltrar }: FiltrosHistoricoProps) {
    const [rascunho, setRascunho] = useState<Filtros>(filtrosAplicados);

    useEffect(() => setRascunho(filtrosAplicados), [filtrosAplicados]);

    function alterar(campo: keyof Filtros, valor: string) {
        setRascunho((atual) => ({ ...atual, [campo]: valor }));
    }

    function filtrar(evento: FormEvent) {
        evento.preventDefault();
        aoFiltrar(rascunho);
    }

    return (
        <form onSubmit={filtrar} className="flex flex-wrap items-center gap-2.5 text-xs text-slate-700 py-1.5">
            <label className={classeCampo}>
                <span className="font-medium text-slate-600">Cidade:</span>
                <select
                    value={rascunho.cidade}
                    onChange={(evento) => alterar('cidade', evento.target.value)}
                    className="bg-transparent border-0 p-0 text-xs font-semibold text-slate-800 focus:outline-none cursor-pointer pr-1"
                >
                    <option value="">Todas</option>
                    {cidades.map((cidade) => (
                        <option key={cidade} value={cidade}>
                            {cidade}
                        </option>
                    ))}
                </select>
            </label>

            <label className={classeCampo}>
                <span className="font-medium text-slate-600">De:</span>
                <Icone nome="calendar_today" className="text-slate-500 text-[14px]" />
                <input
                    type="datetime-local"
                    value={rascunho.de}
                    onChange={(evento) => alterar('de', evento.target.value)}
                    className="text-xs tabular-nums text-slate-800 outline-none border-0 p-0 bg-transparent"
                />
            </label>

            <label className={classeCampo}>
                <span className="font-medium text-slate-600">Até:</span>
                <Icone nome="schedule" className="text-slate-500 text-[14px]" />
                <input
                    type="datetime-local"
                    value={rascunho.ate}
                    min={rascunho.de || undefined}
                    onChange={(evento) => alterar('ate', evento.target.value)}
                    className="text-xs tabular-nums text-slate-800 outline-none border-0 p-0 bg-transparent"
                />
            </label>

            <div className="flex items-center gap-2 ml-auto">
                <button
                    type="submit"
                    className="inline-flex items-center gap-1.5 px-4 py-1.5 bg-primary-strong hover:bg-primary-hover text-white rounded text-xs font-semibold transition-colors cursor-pointer"
                >
                    <Icone nome="filter_alt" className="text-[14px]" />
                    Filtrar
                </button>
                <button
                    type="button"
                    onClick={() => aoFiltrar(FILTROS_VAZIOS)}
                    className="px-3 py-1.5 bg-white hover:bg-slate-100 border border-slate-300 text-slate-700 rounded text-xs font-medium transition-colors cursor-pointer"
                >
                    Limpar
                </button>
            </div>
        </form>
    );
}
```

### `resources/js/components/TabelaHistorico.tsx`

Tabela com a linha selecionada em azul corporativo e o ícone `radio_button_checked`. Clicar em uma linha a seleciona e atualiza o card.

```tsx
import type { ConsultaClima } from '../types/clima';
import { capitalizar, formatarData, formatarHora, formatarTemperatura } from '../utils/formatadores';
import { Icone } from './Icone';

interface TabelaHistoricoProps {
    consultas: ConsultaClima[];
    selecionadaId: number | null;
    carregando: boolean;
    aoSelecionar: (consulta: ConsultaClima) => void;
}

const COLUNAS = [
    { titulo: 'CIDADE', alinhamento: 'text-left' },
    { titulo: 'CONSULTA', alinhamento: 'text-left' },
    { titulo: 'TEMP.', alinhamento: 'text-right' },
    { titulo: 'SENSAÇÃO', alinhamento: 'text-right' },
    { titulo: 'UMIDADE', alinhamento: 'text-right' },
    { titulo: 'DESCRIÇÃO', alinhamento: 'text-left' },
];

export function TabelaHistorico({ consultas, selecionadaId, carregando, aoSelecionar }: TabelaHistoricoProps) {
    return (
        <div className={`overflow-x-auto transition-opacity ${carregando ? 'opacity-60' : ''}`} aria-busy={carregando}>
            <table className="w-full text-left text-xs border-collapse">
                <thead>
                    <tr className="bg-surface-tint text-[#476077] border-b border-border-tint font-bold select-none text-[11px] tracking-wider">
                        {COLUNAS.map(({ titulo, alinhamento }) => (
                            <th key={titulo} scope="col" className={`py-2 px-2 first:px-3 border-r last:border-r-0 border-border-tint ${alinhamento}`}>
                                {titulo}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody className="divide-y divide-[#E6EEF5]">
                    {consultas.length === 0 && (
                        <tr>
                            <td colSpan={COLUNAS.length} className="py-8 text-center text-text-muted">
                                {carregando ? 'Carregando histórico…' : 'Nenhuma consulta encontrada para os filtros selecionados.'}
                            </td>
                        </tr>
                    )}

                    {consultas.map((consulta) => {
                        const selecionada = consulta.id === selecionadaId;
                        const celula = `py-2 px-2 whitespace-nowrap border-r ${selecionada ? 'border-primary' : 'border-[#E6EEF5]'}`;

                        return (
                            <tr
                                key={consulta.id}
                                onClick={() => aoSelecionar(consulta)}
                                aria-selected={selecionada}
                                className={`cursor-pointer ${selecionada ? 'bg-primary-dark text-white font-medium shadow-sm' : 'bg-white hover:bg-slate-50'}`}
                            >
                                <td className={`${celula} px-3 font-semibold ${selecionada ? 'font-bold' : 'text-slate-800'}`}>
                                    <span className="flex items-center gap-1.5">
                                        {selecionada && <Icone nome="radio_button_checked" className="text-[17px] text-[#7DD0FF]" />}
                                        {consulta.cidade}
                                    </span>
                                </td>
                                <td className={`${celula} tabular-nums text-[11px] ${selecionada ? 'text-sky-100' : 'text-slate-700'}`}>
                                    {formatarData(consulta.consultado_em)}
                                    <br />
                                    <span className={selecionada ? 'text-sky-200' : 'text-slate-500'}>{formatarHora(consulta.consultado_em)}</span>
                                </td>
                                <td className={`${celula} text-right tabular-nums font-semibold ${selecionada ? 'text-white text-[13px] font-bold' : 'text-slate-800'}`}>
                                    {formatarTemperatura(consulta.temperatura)}
                                </td>
                                <td className={`${celula} text-right tabular-nums ${selecionada ? 'text-sky-100' : 'text-slate-600'}`}>
                                    {formatarTemperatura(consulta.sensacao_termica)}
                                </td>
                                <td className={`${celula} text-right tabular-nums ${selecionada ? 'text-sky-100' : 'text-slate-600'}`}>{consulta.umidade}%</td>
                                <td className={`py-2 px-2 ${selecionada ? 'text-sky-100' : 'text-slate-600'}`}>{capitalizar(consulta.descricao)}</td>
                            </tr>
                        );
                    })}
                </tbody>
            </table>
        </div>
    );
}
```

### `resources/js/components/Paginacao.tsx`

"Mostrando X a Y de Z registros", Anterior/Próxima e até 5 números de página centralizados na atual.

```tsx
import type { Paginado } from '../types/clima';
import { Icone } from './Icone';

interface PaginacaoProps {
    pagina: Pick<Paginado<unknown>, 'current_page' | 'last_page' | 'total' | 'from' | 'to'>;
    aoMudarPagina: (pagina: number) => void;
}

const MAXIMO_BOTOES = 5;

/** Até 5 números de página, centralizados na página atual. */
function paginasVisiveis(atual: number, ultima: number): number[] {
    const inicio = Math.max(1, Math.min(atual - Math.floor(MAXIMO_BOTOES / 2), ultima - MAXIMO_BOTOES + 1));
    const fim = Math.min(ultima, inicio + MAXIMO_BOTOES - 1);

    return Array.from({ length: fim - inicio + 1 }, (_, indice) => inicio + indice);
}

const classeNavegacao =
    'inline-flex items-center gap-0.5 px-2 py-1 text-[11px] font-medium text-slate-700 bg-white border border-slate-200 rounded hover:bg-slate-50 transition-colors cursor-pointer disabled:text-slate-400 disabled:cursor-not-allowed disabled:opacity-60';

export function Paginacao({ pagina, aoMudarPagina }: PaginacaoProps) {
    const { current_page: atual, last_page: ultima, total, from, to } = pagina;

    return (
        <div className="flex items-center justify-between gap-2 px-3 py-2.5 bg-surface-canvas border-t border-border-tint text-xs text-slate-600 select-none">
            <span className="text-[11px] font-medium text-slate-500">
                {total === 0 ? (
                    'Nenhum registro'
                ) : (
                    <>
                        Mostrando{' '}
                        <strong className="text-slate-700 font-semibold">
                            {from} a {to}
                        </strong>{' '}
                        de <strong className="text-slate-700 font-semibold">{total}</strong> registros
                    </>
                )}
            </span>

            <nav className="inline-flex items-center gap-1" aria-label="Paginação">
                <button type="button" disabled={atual <= 1} onClick={() => aoMudarPagina(atual - 1)} className={classeNavegacao}>
                    <Icone nome="chevron_left" className="text-[15px]" />
                    Anterior
                </button>

                {paginasVisiveis(atual, ultima).map((numero) => (
                    <button
                        key={numero}
                        type="button"
                        onClick={() => aoMudarPagina(numero)}
                        aria-current={numero === atual ? 'page' : undefined}
                        className={`w-6 h-6 inline-flex items-center justify-center text-[11px] rounded cursor-pointer ${
                            numero === atual
                                ? 'font-bold text-white bg-primary-strong'
                                : 'font-medium text-slate-700 bg-white border border-slate-200 hover:bg-slate-50'
                        }`}
                    >
                        {numero}
                    </button>
                ))}

                <button type="button" disabled={atual >= ultima} onClick={() => aoMudarPagina(atual + 1)} className={classeNavegacao}>
                    Próxima
                    <Icone nome="chevron_right" className="text-[15px]" />
                </button>
            </nav>
        </div>
    );
}
```

### `resources/js/components/WidgetCondicao.tsx`

Quadradinho com o ícone e a descrição da condição (usa `visualDoClima`).

```tsx
import { capitalizar } from '../utils/formatadores';
import { visualDoClima } from '../utils/iconeClima';
import { Icone } from './Icone';

export function WidgetCondicao({ descricao }: { descricao: string }) {
    const visual = visualDoClima(descricao);

    return (
        <div className={`flex flex-col items-center justify-center p-2.5 rounded-lg border shadow-2xs w-24 ${visual.fundo} ${visual.borda}`}>
            <Icone nome={visual.icone} preenchido={visual.preenchido} className={`text-3xl ${visual.corIcone}`} />
            <span className="text-[11px] font-semibold text-slate-700 mt-1 text-center leading-tight">{capitalizar(descricao)}</span>
        </div>
    );
}
```

### `resources/js/components/CurvaTemperatura.tsx`

Gráfico em SVG puro, sem biblioteca: linha com área em gradiente, pontos (passe o mouse para ver data e temperatura), rótulo de **Pico** e rótulos da primeira e da última leitura. Os pontos ficam igualmente espaçados, um por consulta.

```tsx
import { useId } from 'react';
import type { ConsultaClima } from '../types/clima';
import { formatarDiaMesHora, formatarNumero, formatarTemperatura } from '../utils/formatadores';

interface CurvaTemperaturaProps {
    /** Consultas em ordem cronológica (da mais antiga para a mais recente). */
    consultas: ConsultaClima[];
    selecionadaId: number | null;
}

const LARGURA = 260;
const ALTURA = 120;
const X_INICIAL = 35;
const X_FINAL = 235;
const Y_TOPO = 28;
const Y_BASE = 95;
const LARGURA_ROTULO_PICO = 66;

/** Rótulo acima do ponto; se o ponto estiver colado no topo (área do "Pico"), vai para baixo. */
function posicaoRotulo(y: number): number {
    return y < Y_TOPO + 12 ? y + 14 : y - 8;
}

export function CurvaTemperatura({ consultas, selecionadaId }: CurvaTemperaturaProps) {
    const idGradiente = useId();

    if (consultas.length < 2) {
        return (
            <div className="w-full h-36 bg-surface-canvas border border-border-subtle rounded-lg flex items-center justify-center px-6 text-center text-[11px] text-text-muted">
                É preciso ao menos duas consultas desta cidade para traçar a curva de variação.
            </div>
        );
    }

    const temperaturas = consultas.map((consulta) => consulta.temperatura);
    const minima = Math.min(...temperaturas);
    const maxima = Math.max(...temperaturas);
    const amplitude = maxima - minima || 1;

    const pontos = consultas.map((consulta, indice) => ({
        consulta,
        x: X_INICIAL + (indice * (X_FINAL - X_INICIAL)) / (consultas.length - 1),
        y: maxima === minima ? (Y_TOPO + Y_BASE) / 2 : Y_BASE - ((consulta.temperatura - minima) / amplitude) * (Y_BASE - Y_TOPO),
    }));

    const linha = pontos.map(({ x, y }, indice) => `${indice === 0 ? 'M' : 'L'} ${x.toFixed(1)} ${y.toFixed(1)}`).join(' ');
    const area = `${linha} L ${X_FINAL} 105 L ${X_INICIAL} 105 Z`;

    const primeiro = pontos[0];
    const ultimo = pontos[pontos.length - 1];
    const pico = pontos.reduce((maior, ponto) => (ponto.consulta.temperatura > maior.consulta.temperatura ? ponto : maior));
    const xRotuloPico = Math.min(Math.max(pico.x - LARGURA_ROTULO_PICO / 2, 0), LARGURA - LARGURA_ROTULO_PICO);

    return (
        <div className="w-full h-36 bg-surface-canvas border border-border-subtle rounded-lg p-2">
            <svg className="w-full h-full overflow-visible" viewBox={`0 0 ${LARGURA} ${ALTURA}`} preserveAspectRatio="none" role="img" aria-label="Curva de temperatura das consultas da cidade">
                <defs>
                    <linearGradient id={idGradiente} x1="0%" x2="0%" y1="0%" y2="100%">
                        <stop offset="0%" stopColor="#007BB3" stopOpacity="0.35" />
                        <stop offset="100%" stopColor="#007BB3" stopOpacity="0.02" />
                    </linearGradient>
                </defs>

                {[Y_TOPO, (Y_TOPO + Y_BASE) / 2, Y_BASE].map((y) => (
                    <line key={y} x1={25} x2={245} y1={y} y2={y} stroke="#E2E8F0" strokeDasharray="3,3" strokeWidth={1} />
                ))}

                <path d={area} fill={`url(#${idGradiente})`} />
                <path d={linha} fill="none" stroke="#007BB3" strokeLinecap="round" strokeLinejoin="round" strokeWidth={2.5} />

                {pontos.map(({ consulta, x, y }) => {
                    const destacado = consulta.id === selecionadaId || consulta === pico.consulta;

                    return (
                        <circle key={consulta.id} cx={x} cy={y} r={destacado ? 4 : 3} fill={destacado ? '#007BB3' : '#FFFFFF'} stroke={destacado ? '#FFFFFF' : '#007BB3'} strokeWidth={2}>
                            <title>{`${formatarDiaMesHora(consulta.consultado_em)} · ${formatarTemperatura(consulta.temperatura)}`}</title>
                        </circle>
                    );
                })}

                <g transform={`translate(${xRotuloPico}, 3)`}>
                    <rect fill="#00283C" height={15} rx={3} width={LARGURA_ROTULO_PICO} />
                    <text fill="#FFFFFF" fontFamily="sans-serif" fontSize={8} fontWeight="bold" textAnchor="middle" x={LARGURA_ROTULO_PICO / 2} y={11}>
                        Pico: {formatarTemperatura(maxima)}
                    </text>
                </g>

                <text fill="#64748B" fontFamily="monospace" fontSize={8} textAnchor="start" x={primeiro.x - 4} y={posicaoRotulo(primeiro.y)}>
                    {formatarNumero(primeiro.consulta.temperatura)}°
                </text>
                <text fill="#007BB3" fontFamily="monospace" fontSize={8} fontWeight="bold" textAnchor="end" x={ultimo.x + 4} y={posicaoRotulo(ultimo.y)}>
                    {formatarNumero(ultimo.consulta.temperatura)}°C
                </text>

                <text fill="#94A3B8" fontFamily="monospace" fontSize={7.5} textAnchor="start" x={25} y={115}>
                    {formatarDiaMesHora(consultas[0].consultado_em)}
                </text>
                <text fill="#94A3B8" fontFamily="monospace" fontSize={7.5} textAnchor="end" x={245} y={115}>
                    {formatarDiaMesHora(consultas[consultas.length - 1].consultado_em)}
                </text>
            </svg>
        </div>
    );
}
```

### `resources/js/components/MiniEstatisticas.tsx`

Os três mini-cards: Mínima (com o horário), Média (com o número de consultas) e Máxima (com o horário).

```tsx
import type { EstatisticasTemperatura } from '../utils/estatisticas';
import { formatarHora, formatarTemperatura } from '../utils/formatadores';
import { Icone } from './Icone';

interface TileProps {
    icone: string;
    rotulo: string;
    valor: string;
    detalhe: string;
    destaque: string;
}

function Tile({ icone, rotulo, valor, detalhe, destaque }: TileProps) {
    return (
        <div className="bg-surface-tint border border-border-tint rounded-lg p-2 flex flex-col items-center text-center">
            <div className={`flex items-center gap-0.5 text-[10px] font-bold ${destaque}`}>
                <Icone nome={icone} className="text-[12px]" />
                {rotulo}
            </div>
            <span className={`tabular-nums font-bold text-xs mt-0.5 ${rotulo === 'Máxima' ? 'text-rose-600' : 'text-slate-800'}`}>{valor}</span>
            <span className="text-[9px] text-slate-500">{detalhe}</span>
        </div>
    );
}

export function MiniEstatisticas({ estatisticas }: { estatisticas: EstatisticasTemperatura }) {
    const { minima, maxima, media, quantidade } = estatisticas;

    return (
        <div className="grid grid-cols-3 gap-2">
            <Tile icone="arrow_downward" rotulo="Mínima" valor={formatarTemperatura(minima.temperatura)} detalhe={`${formatarHora(minima.consultado_em)} h`} destaque="text-primary" />
            <Tile icone="bar_chart" rotulo="Média" valor={formatarTemperatura(media)} detalhe={`${quantidade} consulta${quantidade === 1 ? '' : 's'}`} destaque="text-slate-600" />
            <Tile icone="arrow_upward" rotulo="Máxima" valor={formatarTemperatura(maxima.temperatura)} detalhe={`${formatarHora(maxima.consultado_em)} h`} destaque="text-rose-600" />
        </div>
    );
}
```

### `resources/js/components/CardTelemetria.tsx`

Card da direita: leitura selecionada, sensação, condição, curva, estatísticas, umidade e velocidade do vento. Sem seleção, mostra um estado vazio.

```tsx
import type { ConsultaClima } from '../types/clima';
import { calcularEstatisticas, emOrdemCronologica } from '../utils/estatisticas';
import { classificarUmidade, formatarDiaMesHora, formatarNumero, formatarTemperatura } from '../utils/formatadores';
import { CurvaTemperatura } from './CurvaTemperatura';
import { Icone } from './Icone';
import { MiniEstatisticas } from './MiniEstatisticas';
import { WidgetCondicao } from './WidgetCondicao';

interface CardTelemetriaProps {
    consulta: ConsultaClima | null;
    /** Consultas da mesma cidade, como vêm da API (mais recente primeiro). */
    consultasDaCidade: ConsultaClima[];
    carregando: boolean;
}

export function CardTelemetria({ consulta, consultasDaCidade, carregando }: CardTelemetriaProps) {
    if (consulta === null) {
        return (
            <div className="lg:col-span-5 border border-border-tint rounded-xl p-6 bg-white shadow-sm flex flex-col items-center justify-center gap-2 text-center min-h-64">
                <Icone nome="location_on" className="text-primary text-3xl" />
                <p className="text-sm font-semibold text-slate-700">Nenhuma consulta selecionada</p>
                <p className="text-xs text-text-muted">Consulte uma cidade ou clique em uma linha do histórico para ver os detalhes.</p>
            </div>
        );
    }

    const cronologicas = emOrdemCronologica(consultasDaCidade);
    const estatisticas = calcularEstatisticas(cronologicas);
    const ehUltimaLeitura = consultasDaCidade.length > 0 && consultasDaCidade[0].id === consulta.id;
    const umidade = classificarUmidade(consulta.umidade);

    return (
        <div className={`lg:col-span-5 border border-border-tint rounded-xl p-4 bg-white shadow-sm flex flex-col gap-3.5 transition-opacity ${carregando ? 'opacity-70' : ''}`}>
            <div className="flex items-start justify-between gap-2">
                <div className="flex items-start gap-2">
                    <Icone nome="location_on" className="text-primary text-xl mt-0.5" />
                    <h3 className="font-display text-base font-bold text-slate-900 leading-tight">Temperatura — {consulta.cidade}</h3>
                </div>
                {ehUltimaLeitura ? (
                    <span className="inline-flex items-center gap-1.5 px-2.5 py-1 bg-[#E1EEF8] text-primary-dark rounded-full text-[11px] font-bold whitespace-nowrap">
                        <span className="w-1.5 h-1.5 rounded-full bg-primary animate-pulse" />
                        Última leitura
                    </span>
                ) : (
                    <span className="inline-flex items-center gap-1.5 px-2.5 py-1 bg-slate-100 text-slate-600 rounded-full text-[11px] font-bold whitespace-nowrap">
                        <Icone nome="history" className="text-[13px]" />
                        Leitura anterior
                    </span>
                )}
            </div>

            <div className="bg-[#F3F8FC] border border-[#DEECF8] rounded-xl p-3 flex items-center justify-between gap-3">
                <div className="flex flex-col">
                    <span className="text-[10px] font-bold text-[#56738E] uppercase tracking-wider">Leitura em {formatarDiaMesHora(consulta.consultado_em)}</span>
                    <div className="flex items-baseline gap-1 mt-0.5">
                        <span className="font-display text-3xl font-extrabold text-slate-900 tabular-nums tracking-tight">{formatarNumero(consulta.temperatura)}</span>
                        <span className="text-base font-bold text-primary">°C</span>
                    </div>
                    <div className="flex items-center gap-1 mt-1 text-[11px] text-slate-600 font-medium">
                        <Icone nome="device_thermostat" className="text-primary text-sm" />
                        Sensação: <strong className="text-slate-800 tabular-nums">{formatarTemperatura(consulta.sensacao_termica)}</strong>
                    </div>
                </div>
                <WidgetCondicao descricao={consulta.descricao} />
            </div>

            <div className="flex flex-col gap-1.5">
                <div className="flex items-center justify-between text-xs">
                    <div className="flex items-center gap-1.5 font-bold text-slate-800 text-[11px] tracking-wide uppercase">
                        <Icone nome="show_chart" className="text-primary text-base" />
                        Curva de variação
                    </div>
                    {cronologicas.length > 1 && (
                        <span className="text-[11px] tabular-nums text-slate-500">
                            {formatarDiaMesHora(cronologicas[0].consultado_em)} – {formatarDiaMesHora(cronologicas[cronologicas.length - 1].consultado_em)}
                        </span>
                    )}
                </div>
                <CurvaTemperatura consultas={cronologicas} selecionadaId={consulta.id} />
            </div>

            {estatisticas && <MiniEstatisticas estatisticas={estatisticas} />}

            <div className="grid grid-cols-2 gap-2 pt-0.5">
                <div className="bg-surface-canvas border border-border-subtle rounded-lg p-2 flex items-center gap-2">
                    <Icone nome="water_drop" className="text-primary text-lg" />
                    <div className="flex flex-col">
                        <span className="text-[9px] font-medium text-slate-500 leading-none">Umidade relativa</span>
                        <span className="text-xs font-bold text-slate-800 mt-0.5 tabular-nums">
                            {consulta.umidade}% {umidade && <span className={`text-[10px] font-normal ${umidade.classe}`}>({umidade.rotulo})</span>}
                        </span>
                    </div>
                </div>
                <div className="bg-surface-canvas border border-border-subtle rounded-lg p-2 flex items-center gap-2">
                    <Icone nome="air" className="text-primary text-lg" />
                    <div className="flex flex-col">
                        <span className="text-[9px] font-medium text-slate-500 leading-none">Velocidade do vento</span>
                        <span className="text-xs font-bold text-slate-800 mt-0.5 tabular-nums">
                            {consulta.vento_kmh === null ? '—' : `${formatarNumero(consulta.vento_kmh)} km/h`}
                        </span>
                    </div>
                </div>
            </div>
        </div>
    );
}
```

### `resources/js/components/PainelHistorico.tsx`

Bloco do histórico: cabeçalho com Recarregar, filtros, erro de carregamento, tabela com paginação, o card e o rodapé com o total.

```tsx
import type { EstadoRequisicao } from '../hooks/useRequisicao';
import type { ConsultaClima, FiltrosHistorico as Filtros, Paginado } from '../types/clima';
import { CardTelemetria } from './CardTelemetria';
import { FiltrosHistorico } from './FiltrosHistorico';
import { Icone } from './Icone';
import { Paginacao } from './Paginacao';
import { TabelaHistorico } from './TabelaHistorico';

interface PainelHistoricoProps {
    historico: EstadoRequisicao<Paginado<ConsultaClima>>;
    cidades: string[];
    filtros: Filtros;
    selecionada: ConsultaClima | null;
    consultasDaCidade: ConsultaClima[];
    carregandoCidade: boolean;
    aoFiltrar: (filtros: Filtros) => void;
    aoMudarPagina: (pagina: number) => void;
    aoSelecionar: (consulta: ConsultaClima) => void;
    aoRecarregar: () => void;
}

export function PainelHistorico({
    historico,
    cidades,
    filtros,
    selecionada,
    consultasDaCidade,
    carregandoCidade,
    aoFiltrar,
    aoMudarPagina,
    aoSelecionar,
    aoRecarregar,
}: PainelHistoricoProps) {
    const pagina = historico.dados;

    return (
        <section className="bg-white rounded-lg border border-border-strong p-5 shadow-sm flex flex-col gap-4">
            <div className="flex items-start justify-between pb-3 border-b border-slate-100">
                <div className="flex items-start gap-2.5">
                    <Icone nome="history_toggle_off" className="mt-0.5 text-primary text-2xl" />
                    <div>
                        <h2 className="font-display text-base font-bold text-slate-900 tracking-tight leading-tight">Histórico de Consultas</h2>
                        <p className="text-xs text-slate-500 mt-0.5">Consultas meteorológicas registradas, das mais recentes para as mais antigas</p>
                    </div>
                </div>
                <button
                    type="button"
                    onClick={aoRecarregar}
                    disabled={historico.carregando}
                    className="inline-flex items-center gap-1.5 px-3 py-1.5 bg-[#F0F6FB] hover:bg-[#E2EDF7] border border-[#CDE1F2] text-xs font-semibold text-primary-dark rounded transition-colors cursor-pointer disabled:cursor-wait"
                >
                    <Icone nome="sync" className={`text-sm ${historico.carregando ? 'animate-spin' : ''}`} />
                    Recarregar
                </button>
            </div>

            <FiltrosHistorico cidades={cidades} filtrosAplicados={filtros} aoFiltrar={aoFiltrar} />

            {historico.erro && (
                <p role="alert" className="flex items-center gap-2 rounded border border-rose-200 bg-rose-50 px-3 py-2 text-xs font-medium text-rose-800">
                    <Icone nome="error" className="text-[16px] text-rose-600" />
                    {historico.erro}
                </p>
            )}

            <div className="grid grid-cols-1 lg:grid-cols-12 gap-5 items-start pt-1">
                <div className="lg:col-span-7 border border-border-tint rounded-lg overflow-hidden">
                    <TabelaHistorico
                        consultas={pagina?.data ?? []}
                        selecionadaId={selecionada?.id ?? null}
                        carregando={historico.carregando}
                        aoSelecionar={aoSelecionar}
                    />
                    {pagina && <Paginacao pagina={pagina} aoMudarPagina={aoMudarPagina} />}
                </div>

                <CardTelemetria consulta={selecionada} consultasDaCidade={consultasDaCidade} carregando={carregandoCidade} />
            </div>

            <div className="flex flex-col md:flex-row md:items-center justify-between gap-2 pt-2 border-t border-slate-100 text-[11px] text-slate-500">
                <span>Mais recentes primeiro · A curva usa as consultas da cidade selecionada dentro do período filtrado.</span>
                <span className="font-medium text-slate-700 bg-slate-100 px-2 py-0.5 rounded">
                    Histórico carregado: {pagina?.total ?? 0} registro(s)
                </span>
            </div>
        </section>
    );
}
```

### Raiz

### `resources/js/App.tsx`

Estado da tela: filtros aplicados, página, versão, registro selecionado, consulta em andamento, erro e aviso.
- Ao consultar: seleciona o registro devolvido, volta para a página 1 e incrementa a versão (recarrega histórico, card e cidades).
- Se nada estiver selecionado, seleciona a primeira linha.
- Se o registro selecionado mudar no servidor (consulta repetida), troca pela versão nova.

```tsx
import { useEffect, useRef, useState } from 'react';
import { foiCancelada } from './api/client';
import { registrarConsulta } from './api/clima';
import { Alerta } from './components/Alerta';
import { BarraConsulta } from './components/BarraConsulta';
import { Header } from './components/Header';
import { PainelHistorico } from './components/PainelHistorico';
import { useCidadesConsultadas, useConsultasDaCidade, useHistorico } from './hooks/useHistorico';
import { type ConsultaClima, FILTROS_VAZIOS, type FiltrosHistorico } from './types/clima';

export function App() {
    const [filtros, setFiltros] = useState<FiltrosHistorico>(FILTROS_VAZIOS);
    const [pagina, setPagina] = useState(1);
    const [versao, setVersao] = useState(0);
    const [selecionada, setSelecionada] = useState<ConsultaClima | null>(null);
    const [consultando, setConsultando] = useState(false);
    const [erro, setErro] = useState<string | null>(null);
    const [aviso, setAviso] = useState<string | null>(null);
    const controleConsulta = useRef<AbortController | null>(null);

    const historico = useHistorico(filtros, pagina, versao);
    const cidades = useCidadesConsultadas(versao);
    const { consultas: consultasDaCidade, carregando: carregandoCidade } = useConsultasDaCidade(selecionada?.cidade ?? null, filtros, versao);

    useEffect(() => {
        const linhas = historico.dados?.data ?? [];

        if (selecionada === null) {
            if (linhas.length > 0) {
                setSelecionada(linhas[0]);
            }

            return;
        }

        const versaoAtualizada = linhas.find((linha) => linha.id === selecionada.id);

        if (versaoAtualizada && versaoAtualizada.consultado_em !== selecionada.consultado_em) {
            setSelecionada(versaoAtualizada);
        }
    }, [historico.dados, selecionada]);

    async function consultar(cidade: string) {
        controleConsulta.current?.abort();
        const controle = new AbortController();
        controleConsulta.current = controle;

        setConsultando(true);
        setErro(null);
        setAviso(null);

        try {
            const resultado = await registrarConsulta(cidade, controle.signal);

            setSelecionada(resultado.data);
            setAviso(
                resultado.atualizado
                    ? `As condições em ${resultado.data.cidade} não mudaram desde a última consulta: só a data e a hora do registro foram atualizadas.`
                    : null,
            );
            setPagina(1);
            setVersao((atual) => atual + 1);
        } catch (falha) {
            if (foiCancelada(falha)) {
                setAviso('Consulta cancelada.');

                return;
            }

            setErro(falha instanceof Error ? falha.message : 'Erro inesperado.');
        } finally {
            if (controleConsulta.current === controle) {
                controleConsulta.current = null;
                setConsultando(false);
            }
        }
    }

    function aplicarFiltros(novosFiltros: FiltrosHistorico) {
        setFiltros(novosFiltros);
        setPagina(1);
    }

    return (
        <div className="min-h-screen flex flex-col items-center">
            <Header />
            <main className="w-full max-w-6xl px-4 py-6 flex flex-col gap-6">
                <BarraConsulta consultando={consultando} aoConsultar={consultar} aoCancelar={() => controleConsulta.current?.abort()} />

                {erro && <Alerta tipo="erro" mensagem={erro} aoFechar={() => setErro(null)} />}
                {aviso && <Alerta tipo="info" mensagem={aviso} aoFechar={() => setAviso(null)} />}

                <PainelHistorico
                    historico={historico}
                    cidades={cidades}
                    filtros={filtros}
                    selecionada={selecionada}
                    consultasDaCidade={consultasDaCidade}
                    carregandoCidade={carregandoCidade}
                    aoFiltrar={aplicarFiltros}
                    aoMudarPagina={setPagina}
                    aoSelecionar={setSelecionada}
                    aoRecarregar={() => setVersao((atual) => atual + 1)}
                />
            </main>
        </div>
    );
}
```

### `resources/js/main.tsx`

Ponto de entrada: monta o `App` no `#app` da view Blade.

```tsx
import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { App } from './App';

const raiz = document.getElementById('app');

if (raiz === null) {
    throw new Error('Elemento #app não encontrado em resources/views/app.blade.php.');
}

createRoot(raiz).render(
    <StrictMode>
        <App />
    </StrictMode>,
);
```

## Parte D: Como rodar e validar

```bash
php artisan optimize:clear       # obrigatório: limpa o cache de rotas
php artisan migrate              # cria a coluna vento_kmh
php artisan test                 # esperado: 57 testes passando
npx tsc --noEmit                 # checagem de tipos, sem erros

# desenvolvimento (2 terminais)
php artisan serve
npm run dev
# ou produção: npm run build && php artisan serve
```

Acesse **http://localhost:8000** e siga o roteiro:
1. Digite **"jal"**: aparece a lista com **Jales (SP)** em primeiro lugar. Escolha com o mouse ou com ↓ e Enter.
2. Clique em **Consultar**: a linha nova aparece no topo, selecionada, e o card mostra a leitura, o ícone, a umidade e a velocidade do vento.
3. Clique em **Consultar** de novo logo em seguida. Se o tempo não mudou, aparece o aviso azul "só a data e a hora do registro foram atualizadas" e **nenhuma linha nova** é criada.
4. Consulte a mesma cidade em horários diferentes: a **curva** e as estatísticas Mínima, Média e Máxima ganham pontos.
5. **Filtros:** escolha a cidade e um período e clique em **Filtrar**. **Limpar** volta para tudo.
6. **Paginação:** com mais de 10 registros, use Próxima, Anterior e os números.
7. **Cancelar:** clique logo depois de Consultar. O botão volta ao normal e aparece "Consulta cancelada.". Lembre que o servidor pode terminar e gravar mesmo assim.
8. **Erro:** consulte **"Xyzabc"**: aparece o banner vermelho com "Cidade "Xyzabc" não encontrada no serviço de clima.".

Exemplo de resposta do histórico paginado:

```json
{
  "current_page": 1,
  "data": [
    {
      "id": 12,
      "cidade": "Jales",
      "temperatura": 30.5,
      "sensacao_termica": 29.7,
      "umidade": 34,
      "descricao": "céu limpo",
      "vento_kmh": 14,
      "consultado_em": "2026-10-04T21:25:10.000000Z"
    }
  ],
  "from": 1,
  "last_page": 2,
  "per_page": 10,
  "to": 10,
  "total": 13
}
```

O paginator também devolve `first_page_url`, `links`, `next_page_url` etc., que o front não usa.
