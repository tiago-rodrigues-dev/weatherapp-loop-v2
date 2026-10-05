# Tarefa parte 2: Interface React integrada à API

Guia de implementação do front-end do WeatherApp. Cada arquivo aparece com uma breve descrição e o código completo, na ordem sugerida, no mesmo formato do `implementacao.md`.

**Tudo abaixo foi validado** numa cópia do projeto (com os seus namespaces atuais):
- `php artisan test`: **60 testes / 213 asserções passando**, com ou sem `npm run build`. Antes eram 43; entraram testes de paginação, filtros, cidades, velocidade do vento e código da condição, e o `ExampleTest` foi trocado por um teste da página inicial.
- `npm run build` concluindo com sucesso.
- Teste ponta a ponta no Chrome headless, com uma OpenWeather falsa local:
  - o autocomplete sugere "Jales, SP";
  - Consultar seleciona o registro novo;
  - uma consulta repetida mostra o aviso de "só atualizou a data";
  - Cancelar funciona;
  - uma cidade inexistente mostra o banner 404;
  - o filtro por cidade e período e a paginação funcionam;
  - no celular (390 px) não há rolagem horizontal;
  - nenhum erro no console.

## Atualização: ícone pelo código da condição

Se você já implementou a versão anterior deste guia, aplique só isto:
- **Backend:**
  - crie a migration `2026_10_05_000001_add_condicao_to_consulta_clima_table.php` (nova) e rode `php artisan migrate`;
  - atualize `DadosClima`, `OpenWeatherProvider::mapear()`, o model `ConsultaClima` e o `ConsultaClimaRepository` (`criar` e `atualizarDataConsulta`).
- **Testes:**
  - no `ClimaServiceTest`, os dois helpers ganharam `condicaoId`/`icone`, e o teste de vento virou `vento_ou_icone_diferentes_nao_geram_novo_registro`;
  - no `ClimaApiTest`, o helper `respostaOpenWeather()` ganhou `condition_id` e `icon`, e há 3 testes novos no fim: `salva_codigo_da_condicao_e_icone`, `consulta_redundante_atualiza_o_icone_de_dia_para_noite` e `salva_condicao_nula_quando_a_openweather_nao_envia`.
- **Front: reescrito em JavaScript (sem TypeScript) e simplificado.** Se você já começou a versão em TypeScript, descarte os arquivos `.ts`/`.tsx`, o `tsconfig.json` e as dependências `typescript` e `@types/*`, e siga as Partes B e C de novo. Ficaram menos arquivos:
  - as chamadas de API ficam num único `api.js`;
  - o painel do histórico foi para o `App.jsx`;
  - os mini-cards e o widget de condição foram para o `TelemetryCard.jsx`;
  - o debounce foi para dentro do `useAutocomplete.js`.

## Visão geral

```
GET  /                                   → view Blade que monta o React (resources/js/main.jsx)
GET  /api/municipios?busca=jal           → autocomplete (já existia)
POST /api/clima?cidade=Jales             → consulta e grava (já existia; agora também salva vento, código da condição e ícone)
GET  /api/clima/historico?cidade=&de=&ate=&page=&per_page=   → histórico PAGINADO e filtrado (mudou)
GET  /api/clima/cidades                  → cidades já consultadas, para o select do filtro (novo)
```

Telas e componentes:

```
App (estado da tela, cabeçalho e painel do histórico)
├── SearchBar ── useAutocomplete ── GET /api/municipios
├── Alert (erro / aviso)
├── HistoryFilters ─────────────── GET /api/clima/cidades
├── HistoryTable + Pagination ──── GET /api/clima/historico
└── TelemetryCard ──────────────── GET /api/clima/historico?cidade=X&per_page=50
    └── TemperatureChart (SVG)

Todas as chamadas ficam em api.js e passam pelo hook useRequest.
```

### Decisões importantes
- **Idioma do código:** todo o código do front (arquivos, componentes, funções, variáveis e comentários) está em **inglês** e em **JavaScript**. Ficam em português apenas:
  - os **textos exibidos na tela**;
  - os **nomes dos campos do JSON** da API (`cidade`, `temperatura`, `sensacao_termica`, `umidade`, `descricao`, `vento_kmh`, `consultado_em`, `nome`, `uf`, `atualizado`, `erro`);
  - os **parâmetros da query** (`busca`, `cidade`, `de`, `ate`, `per_page`), porque são o contrato com o backend, que continua em português.

  Os termos da reserva por descrição em `utils/weatherIcon.ts` ("chuva", "nublado"…) também ficam em português, porque são comparados com as descrições em pt_br da OpenWeather.
- **Tabela:** Cidade, Consulta (data/hora), Temp., Sensação, Umidade e Descrição. Sem País, sem Observação e sem chip de status, como você pediu.
- **Ícone da condição:** escolhido pelo **código da condição da OpenWeather** (`condicao_id`, campo `weather[0].id`) e pelo **ícone** (`icone`, campo `weather[0].icon`), em `utils/weatherIcon.ts`. O código não depende de idioma e cobre as 55 condições documentadas em [openweathermap.org/weather-conditions](https://openweathermap.org/weather-conditions). O ícone diz se é dia (`01d`) ou noite (`01n`); à noite, céu limpo vira `clear_night` e poucas nuvens viram `partly_cloudy_night`, com o card escuro do catálogo. Registros antigos, sem código, usam a **descrição como reserva**. Códigos desconhecidos usam `device_thermostat`.
  - **Por que não só a descrição:** a OpenWeather não documenta os textos em pt_br. Mapear por trechos de texto deixava neve sem ícone, podia mostrar chuva forte como chuva leve e não sabia se era noite.
- **Velocidade do vento:** coluna nova e nullable (`vento_kmh`). A OpenWeather manda o vento em m/s (com `units=metric`), e convertemos para km/h (×3,6). A pressão atmosférica **não** é salva nem exibida. A **regra de duplicidade não mudou**: só temperatura, sensação, umidade e descrição contam. Quando o registro é redundante, atualizamos a data/hora **e** o vento, o código da condição e o ícone. O ícone pode passar de dia para noite sem o tempo mudar. Registros antigos (ou respostas sem vento) ficam com `null`, e a tela mostra "—".
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

## Parte A: Backend (vento, condição, filtros e paginação)

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

### `database/migrations/2026_10_05_000001_add_condicao_to_consulta_clima_table.php`

Migration nova: adiciona `condicao_id` (código da condição da OpenWeather, `weather[0].id`, ex.: 800 = céu limpo) e `icone` (`weather[0].icon`, ex.: `01d` de dia e `01n` à noite). As duas são nullable: registros antigos não têm esses dados, e o front usa a descrição como reserva.

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
            $table->unsignedSmallInteger('condicao_id')->nullable()->after('descricao');
            $table->string('icone', 4)->nullable()->after('condicao_id');
        });
    }

    public function down(): void
    {
        Schema::table('consulta_clima', function (Blueprint $table) {
            $table->dropColumn(['condicao_id', 'icone']);
        });
    }
};
```

### `app/DTOs/DadosClima.php`

Ganha `ventoKmh`, `condicaoId` e `icone`, todos **opcionais** (com `null` como padrão) para não quebrar quem cria o DTO sem eles. O `igualA()` **não mudou**: esses campos não contam para a duplicidade.

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
        public ?int $condicaoId = null,
        public ?string $icone = null,
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

Substitua só o método `mapear()`, que agora lê `wind.speed` (m/s → km/h), `weather[0].id` e `weather[0].icon`. O resto do arquivo continua igual.

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
            condicaoId: isset($dados['weather'][0]['id']) ? (int) $dados['weather'][0]['id'] : null,
            icone: $dados['weather'][0]['icon'] ?? null,
        );
    }
```

### `app/Models/Clima/ConsultaClima.php`

Inclui `vento_kmh`, `condicao_id` e `icone` no `$fillable`, e os casts de `vento_kmh` e `condicao_id`.

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
        'condicao_id',
        'icone',
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
            'condicao_id' => 'integer',
            'consultado_em' => 'datetime',
        ];
    }
}
```

### `app/Repositories/Clima/ConsultaClimaRepository.php`

Mudanças:
- `criar()` grava o vento, o código da condição e o ícone;
- `atualizarDataConsulta()` **ganhou o parâmetro `DadosClima $dados`** para atualizar o vento, o código da condição e o ícone junto com a data;
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
            'condicao_id' => $dados->condicaoId,
            'icone' => $dados->icone,
            'consultado_em' => $consultadoEm,
        ]);
    }

    public function atualizarDataConsulta(ConsultaClima $consulta, DadosClima $dados, DateTimeInterface $consultadoEm): ConsultaClima
    {
        $consulta->update([
            'vento_kmh' => $dados->ventoKmh,
            'condicao_id' => $dados->condicaoId,
            'icone' => $dados->icone,
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
- criar ou só atualizar (com vento, código e ícone);
- vento ou ícone diferentes **não** geram registro novo;
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
            'condicaoId' => 800,
            'icone' => '01d',
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
            'condicao_id' => 800,
            'icone' => '01d',
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
    public function vento_ou_icone_diferentes_nao_geram_novo_registro(): void
    {
        $existente = $this->consultaExistente();

        $this->provider->shouldReceive('buscarClimaAtual')
            ->andReturn($this->dados(['ventoKmh' => 30.0, 'icone' => '01n']));
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

**1)** Adicione `use Illuminate\Support\Str;` aos imports e **substitua** o método `respostaOpenWeather()` por estes dois métodos. Agora a resposta falsa tem `wind` e o `id`/`icon` da condição, e há um helper para criar registros direto no banco.

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
            'condition_id' => 800,
            'icon' => '01d',
        ], $sobrescrever);

        return [
            'name' => $dados['name'],
            'main' => [
                'temp' => $dados['temp'],
                'feels_like' => $dados['feels_like'],
                'humidity' => $dados['humidity'],
            ],
            'wind' => ['speed' => $dados['wind_speed']],
            'weather' => [[
                'id' => $dados['condition_id'],
                'description' => $dados['description'],
                'icon' => $dados['icon'],
            ]],
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
- código da condição e ícone salvos, o ícone atualizado de dia para noite numa consulta repetida, e os dois nulos quando a OpenWeather não envia;
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

    #[Test]
    public function salva_codigo_da_condicao_e_icone(): void
    {
        Http::fake([self::URL_OPENWEATHER => Http::response($this->respostaOpenWeather([
            'condition_id' => 502,
            'description' => 'chuva forte',
            'icon' => '10n',
        ]))]);

        $this->postJson('/api/clima?cidade=Campinas')
            ->assertCreated()
            ->assertJsonPath('data.condicao_id', 502)
            ->assertJsonPath('data.icone', '10n');
    }

    #[Test]
    public function consulta_redundante_atualiza_o_icone_de_dia_para_noite(): void
    {
        Http::fakeSequence(self::URL_OPENWEATHER)
            ->push($this->respostaOpenWeather(['icon' => '01d']))
            ->push($this->respostaOpenWeather(['icon' => '01n']));

        $this->postJson('/api/clima?cidade=Campinas')->assertCreated();
        $this->postJson('/api/clima?cidade=Campinas')
            ->assertOk()
            ->assertJsonPath('atualizado', true)
            ->assertJsonPath('data.icone', '01n');

        $this->assertDatabaseCount('consulta_clima', 1);
    }

    #[Test]
    public function salva_condicao_nula_quando_a_openweather_nao_envia(): void
    {
        $resposta = $this->respostaOpenWeather();
        unset($resposta['weather'][0]['id'], $resposta['weather'][0]['icon']);
        Http::fake([self::URL_OPENWEATHER => Http::response($resposta)]);

        $this->postJson('/api/clima?cidade=Campinas')
            ->assertCreated()
            ->assertJsonPath('data.condicao_id', null)
            ->assertJsonPath('data.icone', null);
    }
```

## Parte B: Setup do front-end

### Dependências

```bash
npm install react react-dom
npm install -D @vitejs/plugin-react
```

Na validação foram instaladas `react`/`react-dom` 19.3 e `@vitejs/plugin-react` 6.1.

Apague o `resources/js/app.js`, que estava vazio. O ponto de entrada passa a ser `resources/js/main.jsx`.

### `vite.config.js`

Adiciona o plugin do React e troca a entrada para `main.jsx`. Saem o `bunny`/Instrument Sans, porque as fontes do design (Inter, Plus Jakarta Sans e Material Symbols) vêm do Google Fonts na view.

```js
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/main.jsx'],
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

### `resources/css/app.css`

Tailwind v4: os tokens do `DESIGN.md` e da tela viram classes (`bg-primary`, `text-primary-dark`, `border-border-tint`, `font-display`…). As regras de `.material-symbols-outlined` controlam o ícone vazado ou preenchido (classe `filled`), como no catálogo.

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

.material-symbols-outlined.filled {
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
    @vite(['resources/css/app.css', 'resources/js/main.jsx'])
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

São 13 arquivos. Ordem sugerida: API e utilitários, hooks, componentes e, por fim, `App.jsx` e `main.jsx`.

### API e utilitários

### `resources/js/api.js`

Todas as chamadas à API:
- `request()` monta a query string (ignorando valores vazios), envia `Accept: application/json` e aceita um `AbortSignal`;
- em caso de falha, lança `ApiError` com uma mensagem amigável: o `erro` da API (404, 502, 503), a primeira mensagem de validação (422) ou falha de rede;
- `fetchHistory()` recebe nomes em inglês (`city`, `from`, `to`, `perPage`) e os traduz para os parâmetros da API (`cidade`, `de`, `ate`, `per_page`).

```js
export class ApiError extends Error {}

export function isAbortError(error) {
    return error?.name === 'AbortError';
}

async function errorMessage(response) {
    try {
        const body = await response.json();

        if (body.erro) return body.erro;
        if (body.errors) return Object.values(body.errors)[0][0];
        if (body.message) return body.message;
    } catch {
        // Response body is not JSON: use the generic message below.
    }

    return `Erro inesperado no servidor (HTTP ${response.status}).`;
}

async function request(method, path, params = {}, signal) {
    const query = new URLSearchParams(Object.entries(params).filter(([, value]) => value !== undefined && value !== null && value !== ''));
    let response;

    try {
        response = await fetch(`/api${path}?${query}`, { method, headers: { Accept: 'application/json' }, signal });
    } catch (error) {
        if (isAbortError(error)) throw error;
        throw new ApiError('Não foi possível conectar ao servidor. Verifique sua conexão.');
    }

    if (!response.ok) throw new ApiError(await errorMessage(response));

    return response.json();
}

export async function searchMunicipalities(term, signal) {
    return (await request('GET', '/municipios', { busca: term }, signal)).data;
}

export function registerWeatherQuery(city, signal) {
    return request('POST', '/clima', { cidade: city }, signal);
}

/** `from` and `to` must be ISO 8601 strings (see toIso in utils/format.js). */
export function fetchHistory({ city, from, to, page, perPage }, signal) {
    return request('GET', '/clima/historico', { cidade: city, de: from, ate: to, page, per_page: perPage }, signal);
}

export async function fetchQueriedCities(signal) {
    return (await request('GET', '/clima/cidades', {}, signal)).data;
}
```

### `resources/js/utils/format.js`

Formatação pt-BR (`23,1 °C`, `28/09/2026`, `10:17`) e `toIso()`, que converte o valor do `datetime-local` (horário local) para ISO em UTC, o formato que a API espera.

```js
const decimal = new Intl.NumberFormat('pt-BR', { minimumFractionDigits: 1, maximumFractionDigits: 1 });

export const formatNumber = (value) => decimal.format(value);
export const formatTemperature = (value) => `${formatNumber(value)} °C`;
export const formatDate = (iso) => new Date(iso).toLocaleDateString('pt-BR');
export const formatTime = (iso) => new Date(iso).toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
export const formatDayMonthTime = (iso) => `${new Date(iso).toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit' })} ${formatTime(iso)}`;
export const capitalize = (text) => text.charAt(0).toUpperCase() + text.slice(1);

/** Converts an <input type="datetime-local"> value (browser local time) to ISO 8601 in UTC. */
export const toIso = (value) => (value ? new Date(value).toISOString() : undefined);
```

### `resources/js/utils/weatherIcon.js`

`getWeatherVisual(record)` devolve ícone e cores conforme o catálogo:
1. **Com `condicao_id`:** usa as faixas de código da OpenWeather (2xx trovoada, 3xx garoa, 5xx chuva, 6xx neve, 7xx atmosfera, 800 céu limpo, 801–804 nuvens). O `icone` terminado em `n` ativa as variantes noturnas.
2. **Sem `condicao_id`** (registros antigos): reserva pelos termos da descrição em pt_br.

Foi conferido com as 55 condições oficiais, sem divergências.

```js
const visual = (icon, color, background, border, labelColor = 'text-slate-700') => ({ icon, color, background, border, labelColor });

const THUNDERSTORM = visual('thunderstorm', 'text-indigo-600', 'bg-indigo-50', 'border-indigo-200');
const HEAVY_RAIN = visual('rainy_heavy', 'text-primary-dark', 'bg-blue-100', 'border-blue-200');
const RAIN = visual('rainy', 'text-blue-500', 'bg-blue-50', 'border-blue-200');
const SNOW = visual('weather_snowy', 'text-sky-500', 'bg-sky-50', 'border-sky-200');
const SLEET = visual('weather_mix', 'text-sky-600', 'bg-sky-50', 'border-sky-200');
const FOG = visual('foggy', 'text-slate-600', 'bg-slate-100', 'border-slate-200');
const WIND = visual('air', 'text-teal-600', 'bg-teal-50', 'border-teal-200');
const TORNADO = visual('tornado', 'text-teal-700', 'bg-teal-50', 'border-teal-200');
const CLEAR_DAY = visual('wb_sunny', 'text-amber-500', 'bg-amber-50', 'border-amber-200');
const CLEAR_NIGHT = visual('clear_night', 'text-amber-300', 'bg-slate-900', 'border-slate-800', 'text-white');
const FEW_CLOUDS = visual('filter_drama', 'text-amber-600', 'bg-amber-50', 'border-amber-200');
const PARTLY_CLOUDY = visual('partly_cloudy_day', 'text-sky-500', 'bg-sky-50', 'border-sky-200');
const CLOUDY_NIGHT = visual('partly_cloudy_night', 'text-amber-300', 'bg-slate-900', 'border-slate-800', 'text-white');
const CLOUDY = visual('cloud', 'text-slate-500', 'bg-slate-100', 'border-slate-200');
const UNKNOWN = visual('device_thermostat', 'text-primary', 'bg-white', 'border-border-tint');

/** OpenWeather condition codes: https://openweathermap.org/weather-conditions */
function fromCode(code, night) {
    if (code < 300) return THUNDERSTORM;
    if (code < 400) return RAIN; // drizzle
    if (code === 511) return SLEET; // freezing rain
    if ([502, 503, 504, 522].includes(code)) return HEAVY_RAIN;
    if (code < 600) return RAIN;
    if (code >= 611 && code <= 616) return SLEET;
    if (code < 700) return SNOW;
    if (code === 771) return WIND;
    if (code === 781) return TORNADO;
    if (code < 800) return FOG;
    if (code === 800) return night ? CLEAR_NIGHT : CLEAR_DAY;
    if (code === 801) return night ? CLOUDY_NIGHT : FEW_CLOUDS;
    if (code === 802) return night ? CLOUDY_NIGHT : PARTLY_CLOUDY;
    if (code <= 804) return CLOUDY;
    return UNKNOWN;
}

/** Fallback for old records without `condicao_id`. First matching rule wins, so order matters. */
const DESCRIPTION_RULES = [
    [['trovoada', 'tempestade'], THUNDERSTORM],
    [['neve', 'granizo'], SNOW],
    [['chuva forte', 'chuva muito forte', 'chuva extrema', 'intensidade pesada'], HEAVY_RAIN],
    [['chuva', 'garoa', 'chuvisco'], RAIN],
    [['nevoa', 'neblina', 'nevoeiro', 'fumaca', 'poeira', 'areia', 'cinza'], FOG],
    [['algumas nuvens'], FEW_CLOUDS],
    [['nuvens dispersas'], PARTLY_CLOUDY],
    [['nublado', 'nuvens'], CLOUDY],
    [['limpo'], CLEAR_DAY],
];

function fromDescription(description) {
    const text = description.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
    const rule = DESCRIPTION_RULES.find(([terms]) => terms.some((term) => text.includes(term)));

    return rule ? rule[1] : UNKNOWN;
}

export function getWeatherVisual(record) {
    if (record.condicao_id) return fromCode(record.condicao_id, record.icone?.endsWith('n'));

    return fromDescription(record.descricao);
}
```

### Hooks

### `resources/js/hooks/useRequest.js`

Hook base de todas as buscas: refaz a requisição quando as dependências mudam, **cancela a anterior** (evita que uma resposta velha sobrescreva a nova) e mantém os dados antigos enquanto carrega, para a tabela não piscar ao paginar.

```js
import { useEffect, useState } from 'react';
import { isAbortError } from '../api';

/**
 * Runs `run(signal)` whenever `dependencies` change, aborting the previous request.
 * Keeps the previous data while loading. Pass `null` to skip the request.
 */
export function useRequest(run, dependencies) {
    const [state, setState] = useState({ data: null, loading: run !== null, error: null });

    useEffect(() => {
        if (run === null) {
            setState({ data: null, loading: false, error: null });
            return;
        }

        const controller = new AbortController();
        setState((current) => ({ ...current, loading: true, error: null }));

        run(controller.signal)
            .then((data) => setState({ data, loading: false, error: null }))
            .catch((error) => {
                if (!isAbortError(error)) setState((current) => ({ ...current, loading: false, error: error.message }));
            });

        return () => controller.abort();
    }, dependencies);

    return state;
}
```

### `resources/js/hooks/useAutocomplete.js`

Sugestões de municípios: espera 300 ms depois da última tecla (debounce) e só busca a partir de 2 caracteres.

```js
import { useEffect, useState } from 'react';
import { searchMunicipalities } from '../api';
import { useRequest } from './useRequest';

export const MIN_SEARCH_LENGTH = 2;

export function useAutocomplete(text, enabled) {
    const [term, setTerm] = useState('');

    useEffect(() => {
        const timer = setTimeout(() => setTerm(text.trim()), 300);
        return () => clearTimeout(timer);
    }, [text]);

    const shouldSearch = enabled && term.length >= MIN_SEARCH_LENGTH;
    const { data, loading } = useRequest(shouldSearch ? (signal) => searchMunicipalities(term, signal) : null, [term, shouldSearch]);

    return { suggestions: shouldSearch ? (data ?? []) : [], loading: shouldSearch && loading };
}
```

### Componentes

### `resources/js/components/Icon.jsx`

Atalho para os ícones do Material Symbols (`filled` usa a variante preenchida).

```jsx
/** Material Symbols Outlined icon (font loaded in resources/views/app.blade.php). */
export function Icon({ name, className = '', filled = false }) {
    return (
        <span aria-hidden="true" className={`material-symbols-outlined ${filled ? 'filled' : ''} ${className}`}>
            {name}
        </span>
    );
}
```

### `resources/js/components/LoopLogo.jsx`

Logotipo da Loop Sistemas, copiado do SVG da tela de referência. As classes `.st0`–`.st8` viraram `fill` direto em cada forma.

```jsx
export function LoopLogo({ className = '' }) {
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

### `resources/js/components/Alert.jsx`

Banner de erro (vermelho) ou aviso (azul), com botão de fechar.

```jsx
import { Icon } from './Icon';

const STYLES = {
    error: { box: 'bg-rose-50 border-rose-200 text-rose-800', icon: 'error' },
    info: { box: 'bg-sky-50 border-sky-200 text-sky-800', icon: 'info' },
};

export function Alert({ type, message, onClose }) {
    const style = STYLES[type];

    return (
        <div role="alert" className={`flex items-start gap-2 rounded-lg border px-4 py-3 text-sm ${style.box}`}>
            <Icon name={style.icon} className="text-[20px]" />
            <p className="flex-1 font-medium">{message}</p>
            <button type="button" onClick={onClose} className="cursor-pointer opacity-70 hover:opacity-100" aria-label="Fechar aviso">
                <Icon name="close" className="text-[18px]" />
            </button>
        </div>
    );
}
```

### `resources/js/components/SearchBar.jsx`

Campo de busca com autocomplete e os botões Limpar, Consultar e Cancelar. Escolher uma sugestão preenche "Jales, SP", mas só "Jales" é enviado.

```jsx
import { useState } from 'react';
import { MIN_SEARCH_LENGTH, useAutocomplete } from '../hooks/useAutocomplete';
import { Icon } from './Icon';

export function SearchBar({ searching, onSearch, onCancel }) {
    const [text, setText] = useState('');
    const [listOpen, setListOpen] = useState(false);
    const { suggestions, loading } = useAutocomplete(text, listOpen);

    // "Jales, SP" → "Jales": the API only receives the city name.
    const city = text.split(',')[0].trim();
    const showList = listOpen && text.trim().length >= MIN_SEARCH_LENGTH;

    function submit(event) {
        event.preventDefault();
        setListOpen(false);
        onSearch(city);
    }

    return (
        <form onSubmit={submit} className="bg-white rounded-lg border border-border-strong p-5 shadow-sm">
            <div className="flex flex-col md:flex-row items-center gap-3">
                <div className="relative flex-1 w-full">
                    <div className="flex items-center bg-surface-tint border border-border-tint rounded-lg px-3.5 py-2 shadow-inner focus-within:border-primary">
                        <Icon name="search" className="text-primary text-[18px] mr-2" />
                        <input
                            type="text"
                            value={text}
                            onChange={(event) => {
                                setText(event.target.value);
                                setListOpen(true);
                            }}
                            onBlur={() => setListOpen(false)}
                            placeholder="Digite a cidade, ex.: Jales, SP"
                            aria-label="Cidade"
                            autoComplete="off"
                            className="bg-transparent text-sm font-medium text-slate-800 focus:outline-none w-full placeholder:text-slate-400"
                        />
                        {text && (
                            <button type="button" onClick={() => setText('')} className="flex items-center gap-1 text-[11px] font-bold text-slate-500 hover:text-slate-700 uppercase tracking-wider ml-2 cursor-pointer">
                                <Icon name="cancel" className="text-[15px]" />
                                Limpar
                            </button>
                        )}
                    </div>

                    {showList && (
                        <ul className="absolute z-20 mt-1 w-full max-h-72 overflow-auto bg-white border border-border-strong rounded-lg shadow-lg py-1">
                            {suggestions.length === 0 && <li className="px-3.5 py-2 text-xs text-text-muted">{loading ? 'Buscando cidades…' : 'Nenhuma cidade encontrada.'}</li>}
                            {suggestions.map((municipality) => (
                                <li
                                    key={municipality.id}
                                    // onMouseDown (not onClick) runs before the input's onBlur closes the list.
                                    onMouseDown={(event) => {
                                        event.preventDefault();
                                        setText(`${municipality.nome}, ${municipality.uf}`);
                                        setListOpen(false);
                                    }}
                                    className="flex items-center justify-between px-3.5 py-2 text-sm text-slate-700 cursor-pointer hover:bg-surface-tint"
                                >
                                    <span className="flex items-center gap-2">
                                        <Icon name="location_on" className="text-[16px] text-primary" />
                                        {municipality.nome}
                                    </span>
                                    <span className="px-1.5 py-0.5 bg-[#E1EEF8] text-primary-dark font-bold text-[10px] rounded">{municipality.uf}</span>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>

                <div className="flex items-center gap-2 w-full md:w-auto">
                    <button
                        type="submit"
                        disabled={searching || city.length < MIN_SEARCH_LENGTH}
                        className="flex-1 md:flex-initial inline-flex items-center justify-center gap-2 px-5 py-2.5 bg-primary-strong hover:bg-primary-hover text-white text-sm font-semibold rounded-lg shadow-sm cursor-pointer disabled:cursor-not-allowed disabled:opacity-60"
                    >
                        <Icon name={searching ? 'progress_activity' : 'my_location'} className={`text-[18px] ${searching ? 'animate-spin' : ''}`} />
                        {searching ? 'Consultando…' : 'Consultar'}
                    </button>
                    <button
                        type="button"
                        onClick={onCancel}
                        disabled={!searching}
                        className="flex-1 md:flex-initial inline-flex items-center justify-center gap-1.5 px-4 py-2.5 bg-white hover:bg-slate-50 border border-slate-300 text-slate-700 text-sm font-medium rounded-lg cursor-pointer disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        <Icon name="disabled_by_default" className="text-[17px] text-slate-500" />
                        Cancelar
                    </button>
                </div>
            </div>
        </form>
    );
}
```

### `resources/js/components/HistoryFilters.jsx`

Select de cidade e campos De/Até. Os campos editam um rascunho, e nada é buscado até clicar em Filtrar. Limpar zera e aplica na hora. Também exporta `EMPTY_FILTERS`.

```jsx
import { useEffect, useState } from 'react';
import { Icon } from './Icon';

export const EMPTY_FILTERS = { city: '', from: '', to: '' };

const fieldClass = 'flex items-center gap-1.5 bg-slate-50 border border-slate-200 rounded px-2.5 py-1.5';
const inputClass = 'text-xs tabular-nums text-slate-800 outline-none bg-transparent';

/** Edits a draft; nothing is fetched until "Filtrar" is clicked. */
export function HistoryFilters({ cities, filters, onFilter }) {
    const [draft, setDraft] = useState(filters);

    useEffect(() => setDraft(filters), [filters]);

    const change = (field) => (event) => setDraft({ ...draft, [field]: event.target.value });

    return (
        <form
            onSubmit={(event) => {
                event.preventDefault();
                onFilter(draft);
            }}
            className="flex flex-wrap items-center gap-2.5 text-xs text-slate-700 py-1.5"
        >
            <label className={fieldClass}>
                <span className="font-medium text-slate-600">Cidade:</span>
                <select value={draft.city} onChange={change('city')} className="bg-transparent text-xs font-semibold text-slate-800 focus:outline-none cursor-pointer">
                    <option value="">Todas</option>
                    {cities.map((city) => (
                        <option key={city}>{city}</option>
                    ))}
                </select>
            </label>

            <label className={fieldClass}>
                <span className="font-medium text-slate-600">De:</span>
                <Icon name="calendar_today" className="text-slate-500 text-[14px]" />
                <input type="datetime-local" value={draft.from} onChange={change('from')} className={inputClass} />
            </label>

            <label className={fieldClass}>
                <span className="font-medium text-slate-600">Até:</span>
                <Icon name="schedule" className="text-slate-500 text-[14px]" />
                <input type="datetime-local" value={draft.to} min={draft.from} onChange={change('to')} className={inputClass} />
            </label>

            <div className="flex items-center gap-2 ml-auto">
                <button type="submit" className="inline-flex items-center gap-1.5 px-4 py-1.5 bg-primary-strong hover:bg-primary-hover text-white rounded text-xs font-semibold cursor-pointer">
                    <Icon name="filter_alt" className="text-[14px]" />
                    Filtrar
                </button>
                <button type="button" onClick={() => onFilter(EMPTY_FILTERS)} className="px-3 py-1.5 bg-white hover:bg-slate-100 border border-slate-300 text-slate-700 rounded text-xs font-medium cursor-pointer">
                    Limpar
                </button>
            </div>
        </form>
    );
}
```

### `resources/js/components/HistoryTable.jsx`

Tabela do histórico. A linha selecionada fica em azul, com o ícone `radio_button_checked`.

```jsx
import { capitalize, formatDate, formatTemperature, formatTime } from '../utils/format';
import { Icon } from './Icon';

const COLUMNS = ['CIDADE', 'CONSULTA', 'TEMP.', 'SENSAÇÃO', 'UMIDADE', 'DESCRIÇÃO'];
const RIGHT_ALIGNED = ['TEMP.', 'SENSAÇÃO', 'UMIDADE'];

export function HistoryTable({ records, selectedId, loading, onSelect }) {
    return (
        <div className={`overflow-x-auto ${loading ? 'opacity-60' : ''}`}>
            <table className="w-full text-xs">
                <thead>
                    <tr className="bg-surface-tint text-[#476077] border-b border-border-tint text-[11px] tracking-wider">
                        {COLUMNS.map((title) => (
                            <th key={title} className={`py-2 px-3 border-r last:border-r-0 border-border-tint ${RIGHT_ALIGNED.includes(title) ? 'text-right' : 'text-left'}`}>
                                {title}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody className="divide-y divide-[#E6EEF5]">
                    {records.length === 0 && (
                        <tr>
                            <td colSpan={COLUMNS.length} className="py-8 text-center text-text-muted">
                                {loading ? 'Carregando histórico…' : 'Nenhuma consulta encontrada para os filtros selecionados.'}
                            </td>
                        </tr>
                    )}

                    {records.map((record) => {
                        const selected = record.id === selectedId;
                        const muted = selected ? 'text-sky-100' : 'text-slate-600';

                        return (
                            <tr
                                key={record.id}
                                onClick={() => onSelect(record)}
                                className={`cursor-pointer whitespace-nowrap ${selected ? 'bg-primary-dark text-white' : 'bg-white hover:bg-slate-50 text-slate-800'}`}
                            >
                                <td className="py-2 px-3 font-semibold">
                                    <span className="flex items-center gap-1.5">
                                        {selected && <Icon name="radio_button_checked" className="text-[17px] text-[#7DD0FF]" />}
                                        {record.cidade}
                                    </span>
                                </td>
                                <td className={`py-2 px-3 tabular-nums text-[11px] ${muted}`}>
                                    {formatDate(record.consultado_em)}
                                    <br />
                                    {formatTime(record.consultado_em)}
                                </td>
                                <td className="py-2 px-3 text-right tabular-nums font-semibold">{formatTemperature(record.temperatura)}</td>
                                <td className={`py-2 px-3 text-right tabular-nums ${muted}`}>{formatTemperature(record.sensacao_termica)}</td>
                                <td className={`py-2 px-3 text-right tabular-nums ${muted}`}>{record.umidade}%</td>
                                <td className={`py-2 px-3 ${muted}`}>{capitalize(record.descricao)}</td>
                            </tr>
                        );
                    })}
                </tbody>
            </table>
        </div>
    );
}
```

### `resources/js/components/Pagination.jsx`

"Mostrando X a Y de Z registros", Anterior/Próxima e os números de página próximos da atual.

```jsx
import { Icon } from './Icon';

const buttonClass =
    'inline-flex items-center gap-0.5 px-2 py-1 text-[11px] font-medium text-slate-700 bg-white border border-slate-200 rounded hover:bg-slate-50 cursor-pointer disabled:text-slate-400 disabled:cursor-not-allowed';

/** `page` is the Laravel paginator response (current_page, last_page, total, from, to). */
export function Pagination({ page, onPageChange }) {
    const { current_page: current, last_page: last, total, from, to } = page;
    const numbers = Array.from({ length: last }, (_, index) => index + 1).filter((number) => Math.abs(number - current) <= 2);

    return (
        <div className="flex items-center justify-between gap-2 px-3 py-2.5 bg-surface-canvas border-t border-border-tint text-[11px] text-slate-500">
            <span>{total === 0 ? 'Nenhum registro' : `Mostrando ${from} a ${to} de ${total} registros`}</span>

            <nav className="inline-flex items-center gap-1">
                <button type="button" disabled={current <= 1} onClick={() => onPageChange(current - 1)} className={buttonClass}>
                    <Icon name="chevron_left" className="text-[15px]" />
                    Anterior
                </button>
                {numbers.map((number) => (
                    <button
                        key={number}
                        type="button"
                        onClick={() => onPageChange(number)}
                        className={`w-6 h-6 rounded cursor-pointer ${number === current ? 'font-bold text-white bg-primary-strong' : 'text-slate-700 bg-white border border-slate-200 hover:bg-slate-50'}`}
                    >
                        {number}
                    </button>
                ))}
                <button type="button" disabled={current >= last} onClick={() => onPageChange(current + 1)} className={buttonClass}>
                    Próxima
                    <Icon name="chevron_right" className="text-[15px]" />
                </button>
            </nav>
        </div>
    );
}
```

### `resources/js/components/TemperatureChart.jsx`

Gráfico em SVG puro, sem biblioteca: linha com área em gradiente, um ponto por consulta (passe o mouse para ver data e temperatura) e o rótulo de **Pico**.

```jsx
import { useId } from 'react';
import { formatDayMonthTime, formatTemperature } from '../utils/format';

const WIDTH = 260;
const X_START = 30;
const X_END = 230;
const Y_TOP = 28;
const Y_BOTTOM = 95;

/** SVG line chart, one point per record. `records` must be in chronological order. */
export function TemperatureChart({ records, selectedId }) {
    const gradientId = useId();

    if (records.length < 2) {
        return (
            <div className="h-36 bg-surface-canvas border border-border-subtle rounded-lg flex items-center justify-center px-6 text-center text-[11px] text-text-muted">
                É preciso ao menos duas consultas desta cidade para traçar a curva de variação.
            </div>
        );
    }

    const temperatures = records.map((record) => record.temperatura);
    const min = Math.min(...temperatures);
    const max = Math.max(...temperatures);

    const points = records.map((record, index) => ({
        record,
        x: X_START + (index * (X_END - X_START)) / (records.length - 1),
        y: max === min ? (Y_TOP + Y_BOTTOM) / 2 : Y_BOTTOM - ((record.temperatura - min) / (max - min)) * (Y_BOTTOM - Y_TOP),
    }));
    const line = points.map(({ x, y }, index) => `${index === 0 ? 'M' : 'L'} ${x} ${y}`).join(' ');
    const peak = points.find((point) => point.record.temperatura === max);
    const peakLabelX = Math.min(Math.max(peak.x - 33, 0), WIDTH - 66);

    return (
        <div className="h-36 bg-surface-canvas border border-border-subtle rounded-lg p-2">
            <svg className="w-full h-full overflow-visible" viewBox={`0 0 ${WIDTH} 120`} preserveAspectRatio="none">
                <defs>
                    <linearGradient id={gradientId} x1="0" x2="0" y1="0" y2="1">
                        <stop offset="0%" stopColor="#007BB3" stopOpacity="0.35" />
                        <stop offset="100%" stopColor="#007BB3" stopOpacity="0.02" />
                    </linearGradient>
                </defs>

                <path d={`${line} L ${X_END} 105 L ${X_START} 105 Z`} fill={`url(#${gradientId})`} />
                <path d={line} fill="none" stroke="#007BB3" strokeWidth={2.5} strokeLinejoin="round" />

                {points.map(({ record, x, y }) => {
                    const highlighted = record.id === selectedId || record === peak.record;

                    return (
                        <circle key={record.id} cx={x} cy={y} r={highlighted ? 4 : 3} fill={highlighted ? '#007BB3' : '#FFFFFF'} stroke={highlighted ? '#FFFFFF' : '#007BB3'} strokeWidth={2}>
                            <title>{`${formatDayMonthTime(record.consultado_em)} · ${formatTemperature(record.temperatura)}`}</title>
                        </circle>
                    );
                })}

                <rect x={peakLabelX} y={3} width={66} height={15} rx={3} fill="#00283C" />
                <text x={peakLabelX + 33} y={14} fill="#FFFFFF" fontSize={8} fontWeight="bold" textAnchor="middle">
                    Pico: {formatTemperature(max)}
                </text>

                <text x={X_START - 5} y={115} fill="#94A3B8" fontSize={7.5} fontFamily="monospace">
                    {formatDayMonthTime(records[0].consultado_em)}
                </text>
                <text x={X_END + 5} y={115} fill="#94A3B8" fontSize={7.5} fontFamily="monospace" textAnchor="end">
                    {formatDayMonthTime(records[records.length - 1].consultado_em)}
                </text>
            </svg>
        </div>
    );
}
```

### `resources/js/components/TelemetryCard.jsx`

Card da direita: leitura selecionada, sensação, condição (ícone pelo `weatherIcon.js`), curva, Mínima/Média/Máxima, umidade e velocidade do vento. Sem seleção, mostra uma instrução.

```jsx
import { capitalize, formatDayMonthTime, formatNumber, formatTemperature, formatTime } from '../utils/format';
import { getWeatherVisual } from '../utils/weatherIcon';
import { Icon } from './Icon';
import { TemperatureChart } from './TemperatureChart';

function StatTile({ icon, label, record, value, detail, className = 'text-slate-800' }) {
    return (
        <div className="bg-surface-tint border border-border-tint rounded-lg p-2 flex flex-col items-center text-center">
            <span className={`flex items-center gap-0.5 text-[10px] font-bold ${className}`}>
                <Icon name={icon} className="text-[12px]" />
                {label}
            </span>
            <span className={`tabular-nums font-bold text-xs ${className}`}>{formatTemperature(value ?? record.temperatura)}</span>
            <span className="text-[9px] text-slate-500">{detail ?? `${formatTime(record.consultado_em)} h`}</span>
        </div>
    );
}

function InfoTile({ icon, label, value }) {
    return (
        <div className="bg-surface-canvas border border-border-subtle rounded-lg p-2 flex items-center gap-2">
            <Icon name={icon} className="text-primary text-lg" />
            <div className="flex flex-col">
                <span className="text-[9px] text-slate-500">{label}</span>
                <span className="text-xs font-bold text-slate-800 tabular-nums">{value}</span>
            </div>
        </div>
    );
}

/** `cityRecords`: records of the selected city as returned by the API (newest first). */
export function TelemetryCard({ record, cityRecords }) {
    if (!record) {
        return (
            <div className="lg:col-span-5 border border-border-tint rounded-xl p-6 bg-white shadow-sm text-center text-sm text-text-muted">
                Consulte uma cidade ou clique em uma linha do histórico para ver os detalhes.
            </div>
        );
    }

    const chronological = [...cityRecords].reverse();
    const temperatures = cityRecords.map((item) => item.temperatura);
    const min = cityRecords.find((item) => item.temperatura === Math.min(...temperatures));
    const max = cityRecords.find((item) => item.temperatura === Math.max(...temperatures));
    const average = temperatures.reduce((sum, value) => sum + value, 0) / temperatures.length;
    const isLatest = cityRecords[0]?.id === record.id;
    const condition = getWeatherVisual(record);

    return (
        <div className="lg:col-span-5 border border-border-tint rounded-xl p-4 bg-white shadow-sm flex flex-col gap-3.5">
            <div className="flex items-start justify-between gap-2">
                <h3 className="flex items-center gap-2 font-display text-base font-bold text-slate-900">
                    <Icon name="location_on" className="text-primary text-xl" />
                    Temperatura — {record.cidade}
                </h3>
                <span className={`px-2.5 py-1 rounded-full text-[11px] font-bold whitespace-nowrap ${isLatest ? 'bg-[#E1EEF8] text-primary-dark' : 'bg-slate-100 text-slate-600'}`}>
                    {isLatest ? '● Última leitura' : 'Leitura anterior'}
                </span>
            </div>

            <div className="bg-[#F3F8FC] border border-[#DEECF8] rounded-xl p-3 flex items-center justify-between gap-3">
                <div>
                    <p className="text-[10px] font-bold text-[#56738E] uppercase tracking-wider">Leitura em {formatDayMonthTime(record.consultado_em)}</p>
                    <p className="font-display text-3xl font-extrabold text-slate-900 tabular-nums">
                        {formatNumber(record.temperatura)} <span className="text-base text-primary">°C</span>
                    </p>
                    <p className="flex items-center gap-1 text-[11px] text-slate-600">
                        <Icon name="device_thermostat" className="text-primary text-sm" />
                        Sensação: <strong className="tabular-nums">{formatTemperature(record.sensacao_termica)}</strong>
                    </p>
                </div>
                <div className={`flex flex-col items-center p-2.5 rounded-lg border w-24 ${condition.background} ${condition.border}`}>
                    <Icon name={condition.icon} filled className={`text-3xl ${condition.color}`} />
                    <span className={`text-[11px] font-semibold text-center leading-tight ${condition.labelColor}`}>{capitalize(record.descricao)}</span>
                </div>
            </div>

            <div className="flex flex-col gap-1.5">
                <span className="flex items-center gap-1.5 font-bold text-slate-800 text-[11px] tracking-wide uppercase">
                    <Icon name="show_chart" className="text-primary text-base" />
                    Curva de variação
                </span>
                <TemperatureChart records={chronological} selectedId={record.id} />
            </div>

            {cityRecords.length > 0 && (
                <div className="grid grid-cols-3 gap-2">
                    <StatTile icon="arrow_downward" label="Mínima" record={min} className="text-primary" />
                    <StatTile icon="bar_chart" label="Média" value={average} detail={`${cityRecords.length} consulta(s)`} />
                    <StatTile icon="arrow_upward" label="Máxima" record={max} className="text-rose-600" />
                </div>
            )}

            <div className="grid grid-cols-2 gap-2">
                <InfoTile icon="water_drop" label="Umidade relativa" value={`${record.umidade}%`} />
                <InfoTile icon="air" label="Velocidade do vento" value={record.vento_kmh === null ? '—' : `${formatNumber(record.vento_kmh)} km/h`} />
            </div>
        </div>
    );
}
```

### Raiz

### `resources/js/App.jsx`

Estado da tela e layout:
- filtros, página, registro selecionado, consulta em andamento, erro e aviso;
- `version` é um contador: incrementá-lo recarrega histórico, cidades e card (botão Recarregar e depois de cada consulta);
- ao consultar, seleciona o registro devolvido e volta para a página 1;
- se nada estiver selecionado, seleciona a primeira linha.

```jsx
import { useEffect, useRef, useState } from 'react';
import { fetchHistory, fetchQueriedCities, isAbortError, registerWeatherQuery } from './api';
import { Alert } from './components/Alert';
import { EMPTY_FILTERS, HistoryFilters } from './components/HistoryFilters';
import { HistoryTable } from './components/HistoryTable';
import { Icon } from './components/Icon';
import { LoopLogo } from './components/LoopLogo';
import { Pagination } from './components/Pagination';
import { SearchBar } from './components/SearchBar';
import { TelemetryCard } from './components/TelemetryCard';
import { useRequest } from './hooks/useRequest';
import { toIso } from './utils/format';

export function App() {
    const [filters, setFilters] = useState(EMPTY_FILTERS);
    const [page, setPage] = useState(1);
    const [version, setVersion] = useState(0); // incrementing it reloads every list
    const [selected, setSelected] = useState(null);
    const [searching, setSearching] = useState(false);
    const [error, setError] = useState(null);
    const [notice, setNotice] = useState(null);
    const searchController = useRef(null);

    const period = { from: toIso(filters.from), to: toIso(filters.to) };
    const history = useRequest((signal) => fetchHistory({ city: filters.city, ...period, page, perPage: 10 }, signal), [filters, page, version]);
    const cities = useRequest((signal) => fetchQueriedCities(signal), [version]);
    const cityHistory = useRequest(
        selected ? (signal) => fetchHistory({ city: selected.cidade, ...period, perPage: 50 }, signal) : null,
        [selected?.cidade, filters, version],
    );

    // While a new city loads, the previous city's records are still in memory: keep only the selected city's.
    const cityRecords = (cityHistory.data?.data ?? []).filter((record) => record.cidade === selected?.cidade);

    useEffect(() => {
        if (!selected && history.data?.data.length) setSelected(history.data.data[0]);
    }, [history.data]);

    async function search(city) {
        const controller = new AbortController();
        searchController.current = controller;
        setSearching(true);
        setError(null);
        setNotice(null);

        try {
            const result = await registerWeatherQuery(city, controller.signal);
            setSelected(result.data);
            if (result.atualizado) {
                setNotice(`As condições em ${result.data.cidade} não mudaram desde a última consulta: só a data e a hora do registro foram atualizadas.`);
            }
            setPage(1);
            setVersion((current) => current + 1);
        } catch (failure) {
            if (isAbortError(failure)) setNotice('Consulta cancelada.');
            else setError(failure.message);
        } finally {
            setSearching(false);
        }
    }

    return (
        <div className="min-h-screen">
            <header className="bg-white border-b border-border-strong py-4 shadow-sm flex flex-col items-center gap-1.5">
                <LoopLogo className="h-10 w-auto" />
                <span className="text-xs text-text-muted font-medium">Consulta meteorológica</span>
            </header>

            <main className="max-w-6xl mx-auto px-4 py-6 flex flex-col gap-6">
                <SearchBar searching={searching} onSearch={search} onCancel={() => searchController.current?.abort()} />

                {error && <Alert type="error" message={error} onClose={() => setError(null)} />}
                {notice && <Alert type="info" message={notice} onClose={() => setNotice(null)} />}

                <section className="bg-white rounded-lg border border-border-strong p-5 shadow-sm flex flex-col gap-4">
                    <div className="flex items-start justify-between pb-3 border-b border-slate-100">
                        <div className="flex items-start gap-2.5">
                            <Icon name="history_toggle_off" className="text-primary text-2xl" />
                            <div>
                                <h2 className="font-display text-base font-bold text-slate-900">Histórico de Consultas</h2>
                                <p className="text-xs text-slate-500">Consultas meteorológicas registradas, das mais recentes para as mais antigas</p>
                            </div>
                        </div>
                        <button
                            type="button"
                            onClick={() => setVersion((current) => current + 1)}
                            className="inline-flex items-center gap-1.5 px-3 py-1.5 bg-[#F0F6FB] hover:bg-[#E2EDF7] border border-[#CDE1F2] text-xs font-semibold text-primary-dark rounded cursor-pointer"
                        >
                            <Icon name="sync" className={`text-sm ${history.loading ? 'animate-spin' : ''}`} />
                            Recarregar
                        </button>
                    </div>

                    <HistoryFilters
                        cities={cities.data ?? []}
                        filters={filters}
                        onFilter={(newFilters) => {
                            setFilters(newFilters);
                            setPage(1);
                        }}
                    />

                    {history.error && <p className="rounded border border-rose-200 bg-rose-50 px-3 py-2 text-xs font-medium text-rose-800">{history.error}</p>}

                    <div className="grid grid-cols-1 lg:grid-cols-12 gap-5 items-start">
                        <div className="lg:col-span-7 border border-border-tint rounded-lg overflow-hidden">
                            <HistoryTable records={history.data?.data ?? []} selectedId={selected?.id} loading={history.loading} onSelect={setSelected} />
                            {history.data && <Pagination page={history.data} onPageChange={setPage} />}
                        </div>
                        <TelemetryCard record={selected} cityRecords={cityRecords} />
                    </div>

                    <p className="pt-2 border-t border-slate-100 text-[11px] text-slate-500">
                        Mais recentes primeiro · Histórico carregado: {history.data?.total ?? 0} registro(s)
                    </p>
                </section>
            </main>
        </div>
    );
}
```

### `resources/js/main.jsx`

Ponto de entrada: monta o `App` no `#app` da view Blade.

```jsx
import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { App } from './App';

createRoot(document.getElementById('app')).render(
    <StrictMode>
        <App />
    </StrictMode>,
);
```

## Parte D: Como rodar e validar

```bash
php artisan optimize:clear       # obrigatório: limpa o cache de rotas
php artisan migrate              # cria as colunas vento_kmh, condicao_id e icone
php artisan test                 # esperado: 60 testes passando

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
      "condicao_id": 800,
      "icone": "01d",
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
