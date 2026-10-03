# Implementação: API de Tempo

Guia de implementação da `tarefa-parte1.md`. Cada arquivo aparece com uma breve descrição e o código completo, na ordem sugerida. Todo o código abaixo foi validado numa cópia do projeto: **34 testes / 105 asserções passando**, Pint sem pendências e seed real com 5.595 municípios.

## Visão geral

```
GET  /api/municipios?busca=sao jo        → autocomplete (máx. 10, ignora acento)
POST /api/clima?cidade=Campinas          → consulta a OpenWeather e grava (201 criou / 200 só atualizou a data)
GET  /api/clima/historico[?cidade=X]     → histórico com cache (mais recente primeiro)
```

Fluxo do POST: **Controller → ClimaService → OpenWeatherProvider (API) → ClimaService decide → ConsultaClimaRepository grava → invalida cache**.

Fluxo dos municípios: **Controller → MunicipioService → MunicipioRepository**.

### Decisões importantes
- **Anti-duplicidade:** a nova leitura é comparada com a **última consulta da mesma cidade**. Se temperatura, sensação térmica, umidade e descrição forem iguais, só o `consultado_em` é atualizado. Se qualquer valor mudar, um novo registro é criado.
- **Nome da cidade:** é salvo com a grafia devolvida pela OpenWeather (`"São Paulo"`), junto com um `cidade_slug` (`"sao-paulo"`). O slug é usado na comparação, no filtro do histórico e na chave do cache. Assim, `sao paulo`, `São Paulo` e `SAO PAULO` são a mesma cidade.
- **Cache do histórico:** usa as chaves `clima:historico:todas` e `clima:historico:{slug}`, com TTL de 10 minutos. As duas são apagadas a cada criação ou atualização.
  ⚠️ O cache guarda **arrays**, não models. O `config/cache.php` deste projeto tem `serializable_classes => false`, o que impede o Laravel de desserializar objetos vindos do cache em banco: um model em cache voltaria como `__PHP_Incomplete_Class` e o histórico sairia com campos nulos. Há um teste específico para isso.
- **Erros:** todos voltam no formato `{"erro": "mensagem"}`.

  | Situação | Status |
  |---|---|
  | Parâmetro ausente ou inválido | 422 (formato padrão de validação do Laravel) |
  | Cidade não encontrada na OpenWeather | 404 |
  | Chave inválida/ausente, erro 5xx ou resposta em formato inesperado | 502 |
  | Timeout, falha de conexão ou limite de requisições (429) | 503 |

- **Municípios:** a tabela tem uma coluna `nome_normalizado` (minúsculo e sem acento), porque o `LIKE` do SQLite não trata acentos. Os nomes que **começam** com o termo aparecem primeiro.

> Opcional: o `CLAUDE.md` do projeto recomenda `composer require laravel/boost --dev && php artisan boost:install`. Não é necessário para nada abaixo.

---

## 0. Configuração

### `.env`

Adicione ao final do `.env` **e** do `.env.example`. A chave é gratuita em https://openweathermap.org/api (pode levar alguns minutos para ativar).

```dotenv
OPENWEATHER_API_KEY=sua_chave_aqui
OPENWEATHER_BASE_URL=https://api.openweathermap.org/data/2.5
OPENWEATHER_TIMEOUT=5
```

### `config/services.php`

Adicione este bloco dentro do array retornado (por exemplo, antes de `'slack'`). Centraliza a configuração da API para o provider não ler `env()` diretamente.

```php
    'openweather' => [
        'key' => env('OPENWEATHER_API_KEY'),
        'base_url' => env('OPENWEATHER_BASE_URL', 'https://api.openweathermap.org/data/2.5'),
        'timeout' => (int) env('OPENWEATHER_TIMEOUT', 5),
    ],
```

### `bootstrap/app.php`

Registra o arquivo `routes/api.php`, que ganha o prefixo `/api` automaticamente. A única mudança é a linha `api:`. O `shouldRenderJsonWhen` que já existia garante erros em JSON nas rotas `api/*`.

```php
<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
```

## 1. Migrations

### `database/migrations/2026_10_03_000001_create_municipios_table.php`

Tabela de municípios. `nome_normalizado` é indexado para a busca do autocomplete.

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('municipios', function (Blueprint $table) {
            $table->id();
            $table->string('nome');
            $table->string('nome_normalizado')->index();
            $table->char('uf', 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('municipios');
    }
};
```

### `database/migrations/2026_10_03_000002_create_consulta_clima_table.php`

Tabela `consulta_clima` com os campos pedidos: cidade, data/hora da consulta, temperatura, sensação térmica, umidade e descrição. Tem ainda o `cidade_slug` e um índice composto que acelera a busca da "última consulta da cidade".

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consulta_clima', function (Blueprint $table) {
            $table->id();
            $table->string('cidade');
            $table->string('cidade_slug');
            $table->decimal('temperatura', 5, 2);
            $table->decimal('sensacao_termica', 5, 2);
            $table->unsignedTinyInteger('umidade');
            $table->string('descricao');
            $table->dateTime('consultado_em');
            $table->timestamps();

            $table->index(['cidade_slug', 'consultado_em']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consulta_clima');
    }
};
```

## 2. Models

### `app/Models/Municipio.php`

Model simples da tabela `municipios`.

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Municipio extends Model
{
    protected $table = 'municipios';

    protected $fillable = ['nome', 'nome_normalizado', 'uf'];
}
```

### `app/Models/ConsultaClima.php`

Model da tabela `consulta_clima`. Os `casts` garantem números como `float`/`int` e a data como Carbon. O `$hidden` tira o slug e os timestamps internos do JSON e do array que vai para o cache.

```php
<?php

namespace App\Models;

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
        'consultado_em',
    ];

    protected $hidden = ['cidade_slug', 'created_at', 'updated_at'];

    protected function casts(): array
    {
        return [
            'temperatura' => 'float',
            'sensacao_termica' => 'float',
            'umidade' => 'integer',
            'consultado_em' => 'datetime',
        ];
    }
}
```

## 3. Seeder

### `database/seeders/MunicipioSeeder.php`

Lê o `cidades.json` (formato `estados[].sigla` + `estados[].cidades[]`), gera o nome normalizado e insere em lotes de 500. O `truncate` torna o seeder idempotente: rodar de novo não duplica registros.

```php
<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class MunicipioSeeder extends Seeder
{
    public function run(): void
    {
        $caminho = base_path('cidades.json');

        if (! file_exists($caminho)) {
            throw new RuntimeException("Arquivo {$caminho} não encontrado.");
        }

        $dados = json_decode(file_get_contents($caminho), true, flags: JSON_THROW_ON_ERROR);

        $agora = now();
        $registros = [];

        foreach ($dados['estados'] as $estado) {
            foreach ($estado['cidades'] as $cidade) {
                $registros[] = [
                    'nome' => $cidade,
                    'nome_normalizado' => Str::lower(Str::ascii($cidade)),
                    'uf' => $estado['sigla'],
                    'created_at' => $agora,
                    'updated_at' => $agora,
                ];
            }
        }

        DB::table('municipios')->truncate();

        foreach (array_chunk($registros, 500) as $lote) {
            DB::table('municipios')->insert($lote);
        }
    }
}
```

### `database/seeders/DatabaseSeeder.php`

Inclui a chamada ao `MunicipioSeeder` (linha `$this->call(...)`).

```php
<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $this->call(MunicipioSeeder::class);
    }
}
```

## 4. Municípios (autocomplete): Service + Repository

### `app/Repositories/MunicipioRepository.php`

Acesso ao banco: busca por trecho do nome já normalizado. O `orderByRaw` coloca primeiro os nomes que começam com o termo ("Campinas" antes de "Bom Jesus do Campo").

```php
<?php

namespace App\Repositories;

use App\Models\Municipio;
use Illuminate\Support\Collection;

class MunicipioRepository
{
    public function buscarPorNome(string $termoNormalizado, int $limite): Collection
    {
        return Municipio::query()
            ->where('nome_normalizado', 'like', "%{$termoNormalizado}%")
            ->orderByRaw('CASE WHEN nome_normalizado LIKE ? THEN 0 ELSE 1 END', ["{$termoNormalizado}%"])
            ->orderBy('nome')
            ->limit($limite)
            ->get(['id', 'nome', 'uf']);
    }
}
```

### `app/Services/MunicipioService.php`

Regra de negócio: normaliza o termo digitado (sem acento, minúsculo, sem espaços nas pontas) e aplica o limite de 10 resultados.

```php
<?php

namespace App\Services;

use App\Repositories\MunicipioRepository;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class MunicipioService
{
    public const LIMITE_RESULTADOS = 10;

    public function __construct(private readonly MunicipioRepository $repository) {}

    public function autocomplete(string $termo): Collection
    {
        $termoNormalizado = Str::lower(Str::ascii(trim($termo)));

        return $this->repository->buscarPorNome($termoNormalizado, self::LIMITE_RESULTADOS);
    }
}
```

### `app/Http/Requests/BuscarMunicipioRequest.php`

Valida o `?busca=` com no mínimo 2 caracteres. As mensagens em português aparecem no 422.

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BuscarMunicipioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'busca' => ['required', 'string', 'min:2', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'busca.required' => 'Informe o parâmetro "busca" com parte do nome da cidade.',
            'busca.min' => 'A busca precisa ter pelo menos :min caracteres.',
            'busca.max' => 'A busca pode ter no máximo :max caracteres.',
        ];
    }
}
```

### `app/Http/Resources/MunicipioResource.php`

Formata cada município como `{id, nome, uf}`.

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MunicipioResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nome' => $this->nome,
            'uf' => $this->uf,
        ];
    }
}
```

### `app/Http/Controllers/MunicipioController.php`

Controller enxuto: recebe a request validada e delega ao service.

```php
<?php

namespace App\Http\Controllers;

use App\Http\Requests\BuscarMunicipioRequest;
use App\Http\Resources\MunicipioResource;
use App\Services\MunicipioService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MunicipioController extends Controller
{
    public function __construct(private readonly MunicipioService $service) {}

    public function index(BuscarMunicipioRequest $request): AnonymousResourceCollection
    {
        $municipios = $this->service->autocomplete($request->validated('busca'));

        return MunicipioResource::collection($municipios);
    }
}
```

## 5. Clima

### DTOs

### `app/DTOs/DadosClima.php`

Objeto imutável com os dados já traduzidos da OpenWeather. O método `igualA()` concentra a regra de redundância: compara os 4 valores com a última consulta, arredondando os decimais para 2 casas, que é a precisão do banco.

```php
<?php

namespace App\DTOs;

use App\Models\ConsultaClima;
use Illuminate\Support\Str;

final readonly class DadosClima
{
    public function __construct(
        public string $cidade,
        public float $temperatura,
        public float $sensacaoTermica,
        public int $umidade,
        public string $descricao,
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

### `app/DTOs/ResultadoRegistroClima.php`

Retorno do service: a consulta gravada e se ela foi apenas atualizada (`true`) ou criada (`false`). O controller usa isso para escolher entre 200 e 201.

```php
<?php

namespace App\DTOs;

use App\Models\ConsultaClima;

final readonly class ResultadoRegistroClima
{
    public function __construct(
        public ConsultaClima $consulta,
        public bool $atualizado,
    ) {}
}
```

### Exceções

### `app/Exceptions/ClimaException.php`

Classe base. O Laravel chama `render()` automaticamente, então toda exceção filha vira `{"erro": "..."}` com o status definido em `status()`, sem `try/catch` no controller.

```php
<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

abstract class ClimaException extends Exception
{
    abstract public function status(): int;

    public function render(): JsonResponse
    {
        return response()->json(['erro' => $this->getMessage()], $this->status());
    }
}
```

### `app/Exceptions/CidadeNaoEncontradaException.php`

404: a OpenWeather não conhece a cidade.

```php
<?php

namespace App\Exceptions;

class CidadeNaoEncontradaException extends ClimaException
{
    public static function para(string $cidade): self
    {
        return new self("Cidade \"{$cidade}\" não encontrada no serviço de clima.");
    }

    public function status(): int
    {
        return 404;
    }
}
```

### `app/Exceptions/FalhaApiClimaException.php`

502: a API externa respondeu com erro (chave inválida ou ausente, 5xx, resposta malformada).

```php
<?php

namespace App\Exceptions;

class FalhaApiClimaException extends ClimaException
{
    public function status(): int
    {
        return 502;
    }
}
```

### `app/Exceptions/ServicoClimaIndisponivelException.php`

503: não foi possível falar com a API (timeout, DNS, conexão recusada, limite de requisições).

```php
<?php

namespace App\Exceptions;

class ServicoClimaIndisponivelException extends ClimaException
{
    public function status(): int
    {
        return 503;
    }
}
```

### Provider (API externa)

### `app/Providers/Weather/WeatherProviderInterface.php`

Contrato do provider. O service depende da interface, e não da OpenWeather: isso facilita o mock nos testes unitários e uma eventual troca de API.

```php
<?php

namespace App\Providers\Weather;

use App\DTOs\DadosClima;
use App\Exceptions\ClimaException;

interface WeatherProviderInterface
{
    /**
     * @throws ClimaException
     */
    public function buscarClimaAtual(string $cidade): DadosClima;
}
```

### `app/Providers/Weather/OpenWeatherProvider.php`

Chama `GET /weather?q={cidade},BR&units=metric&lang=pt_br`, traduz cada falha HTTP ou de rede numa exceção com mensagem clara (e registra no log) e converte o JSON em `DadosClima`.

```php
<?php

namespace App\Providers\Weather;

use App\DTOs\DadosClima;
use App\Exceptions\CidadeNaoEncontradaException;
use App\Exceptions\FalhaApiClimaException;
use App\Exceptions\ServicoClimaIndisponivelException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OpenWeatherProvider implements WeatherProviderInterface
{
    public function buscarClimaAtual(string $cidade): DadosClima
    {
        $chave = config('services.openweather.key');

        if (blank($chave)) {
            throw new FalhaApiClimaException('Serviço de clima não configurado: defina OPENWEATHER_API_KEY.');
        }

        try {
            $response = Http::baseUrl(config('services.openweather.base_url'))
                ->timeout(config('services.openweather.timeout'))
                ->acceptJson()
                ->get('/weather', [
                    'q' => "{$cidade},BR",
                    'appid' => $chave,
                    'units' => 'metric',
                    'lang' => 'pt_br',
                ]);
        } catch (ConnectionException $e) {
            Log::warning('OpenWeather inacessível', ['cidade' => $cidade, 'erro' => $e->getMessage()]);

            throw new ServicoClimaIndisponivelException('O serviço de clima está indisponível no momento. Tente novamente em instantes.');
        }

        $this->tratarErros($response, $cidade);

        return $this->mapear($response->json());
    }

    private function tratarErros(Response $response, string $cidade): void
    {
        if ($response->successful()) {
            return;
        }

        Log::warning('OpenWeather retornou erro', [
            'cidade' => $cidade,
            'status' => $response->status(),
            'corpo' => $response->json('message'),
        ]);

        throw match (true) {
            $response->status() === 404 => CidadeNaoEncontradaException::para($cidade),
            $response->status() === 401 => new FalhaApiClimaException('Falha de autenticação com o serviço de clima. Verifique a chave da API.'),
            $response->status() === 429 => new ServicoClimaIndisponivelException('Limite de requisições ao serviço de clima atingido. Tente novamente mais tarde.'),
            default => new FalhaApiClimaException('O serviço de clima retornou um erro inesperado.'),
        };
    }

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
        );
    }
}
```

### `app/Providers/AppServiceProvider.php`

Liga a interface à implementação concreta (linha `bind`).

```php
<?php

namespace App\Providers;

use App\Providers\Weather\OpenWeatherProvider;
use App\Providers\Weather\WeatherProviderInterface;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(WeatherProviderInterface::class, OpenWeatherProvider::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
```

### Repository

### `app/Repositories/ConsultaClimaRepository.php`

Todo acesso à tabela `consulta_clima`: buscar a última consulta da cidade, criar, atualizar só a data e listar o histórico com filtro opcional. A data vem do service, então os testes controlam o relógio.

```php
<?php

namespace App\Repositories;

use App\DTOs\DadosClima;
use App\Models\ConsultaClima;
use DateTimeInterface;
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
            'consultado_em' => $consultadoEm,
        ]);
    }

    public function atualizarDataConsulta(ConsultaClima $consulta, DateTimeInterface $consultadoEm): ConsultaClima
    {
        $consulta->update(['consultado_em' => $consultadoEm]);

        return $consulta;
    }

    public function historico(?string $cidadeSlug = null): Collection
    {
        return ConsultaClima::query()
            ->when($cidadeSlug, fn ($query) => $query->where('cidade_slug', $cidadeSlug))
            ->orderByDesc('consultado_em')
            ->orderByDesc('id')
            ->get();
    }
}
```

### Service

### `app/Services/ClimaService.php`

Coração da regra de negócio.
- `registrarConsulta()`: busca na API, compara com a última consulta da cidade e **atualiza a data** ou **cria** um novo registro. Em ambos os casos, invalida o cache do histórico.
- `historico()`: usa `Cache::remember` com chave por cidade (slug) e guarda arrays, não models.

```php
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
```

### HTTP

### `app/Http/Requests/RegistrarClimaRequest.php`

Valida o `?cidade=` obrigatório. O FormRequest também lê a query string em requisições POST.

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RegistrarClimaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'cidade' => ['required', 'string', 'min:2', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'cidade.required' => 'Informe o parâmetro "cidade" na query string. Ex.: /api/clima?cidade=Campinas',
            'cidade.min' => 'O nome da cidade precisa ter pelo menos :min caracteres.',
            'cidade.max' => 'O nome da cidade pode ter no máximo :max caracteres.',
        ];
    }
}
```

### `app/Http/Requests/HistoricoClimaRequest.php`

Valida o filtro opcional `?cidade=` do histórico.

```php
<?php

namespace App\Http\Requests;

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
        ];
    }
}
```

### `app/Http/Controllers/ClimaController.php`

`store` responde 201 quando cria e 200 quando só atualizou a data, com `atualizado: true|false` no corpo. `historico` devolve `{data: [...]}`.

```php
<?php

namespace App\Http\Controllers;

use App\Http\Requests\HistoricoClimaRequest;
use App\Http\Requests\RegistrarClimaRequest;
use App\Services\ClimaService;
use Illuminate\Http\JsonResponse;

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
        return response()->json([
            'data' => $this->service->historico($request->validated('cidade')),
        ]);
    }
}
```

## 6. Rotas

### `routes/api.php`

Arquivo novo. As rotas recebem o prefixo `/api` pelo `bootstrap/app.php`.

```php
<?php

use App\Http\Controllers\ClimaController;
use App\Http\Controllers\MunicipioController;
use Illuminate\Support\Facades\Route;

Route::get('/municipios', [MunicipioController::class, 'index']);

Route::post('/clima', [ClimaController::class, 'store']);
Route::get('/clima/historico', [ClimaController::class, 'historico']);
```

## 7. Testes

Os testes unitários do service estendem `Tests\TestCase`, e não o TestCase puro do PHPUnit, porque precisam do container para o facade `Cache` (store `array` no `phpunit.xml`). Provider e repository são mocks do Mockery, então não há banco nem HTTP.

### `tests/Unit/ClimaServiceTest.php`

Lógica do service: quando criar e quando só atualizar (com um data provider cobrindo cada campo que pode mudar), nada gravado se a API falhar, cache usado na segunda chamada, chave de cache por cidade, arrays no cache e invalidação após gravar.

```php
<?php

namespace Tests\Unit;

use App\DTOs\DadosClima;
use App\Exceptions\CidadeNaoEncontradaException;
use App\Models\ConsultaClima;
use App\Providers\Weather\WeatherProviderInterface;
use App\Repositories\ConsultaClimaRepository;
use App\Services\ClimaService;
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
```

### `tests/Unit/MunicipioServiceTest.php`

Garante que o termo é normalizado e que o limite de 10 é repassado ao repository. É um teste unitário puro, sem o framework.

```php
<?php

namespace Tests\Unit;

use App\Repositories\MunicipioRepository;
use App\Services\MunicipioService;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class MunicipioServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    #[Test]
    public function normaliza_o_termo_e_aplica_o_limite(): void
    {
        $repository = Mockery::mock(MunicipioRepository::class);
        $repository->shouldReceive('buscarPorNome')
            ->once()
            ->with('sao jo', MunicipioService::LIMITE_RESULTADOS)
            ->andReturn(collect());

        (new MunicipioService($repository))->autocomplete('  São Jo ');

        $this->addToAssertionCount(1);
    }
}
```

### `tests/Feature/ClimaApiTest.php`

Fluxo completo via HTTP com `Http::fake` (a OpenWeather nunca é chamada de verdade; o `preventStrayRequests` garante isso). Cobre 201, repetição idêntica (200, mesmo registro, data nova), mudança de temperatura (2 registros), 422, 404, 502, 503, ordenação e filtro do histórico, invalidação do cache e o cenário real com cache em banco.

```php
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
```

### `tests/Feature/MunicipioApiTest.php`

Autocomplete via HTTP: acento e maiúsculas, prioridade de quem começa com o termo, limite de 10, 422 e o seeder lendo o `cidades.json` real.

```php
<?php

namespace Tests\Feature;

use App\Models\Municipio;
use Database\Seeders\MunicipioSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MunicipioApiTest extends TestCase
{
    use RefreshDatabase;

    private function criarMunicipio(string $nome, string $uf): void
    {
        Municipio::create([
            'nome' => $nome,
            'nome_normalizado' => Str::lower(Str::ascii($nome)),
            'uf' => $uf,
        ]);
    }

    #[Test]
    public function busca_por_parte_do_nome_ignorando_acentos_e_maiusculas(): void
    {
        $this->criarMunicipio('São Paulo', 'SP');
        $this->criarMunicipio('São José dos Campos', 'SP');
        $this->criarMunicipio('Recife', 'PE');

        $this->getJson('/api/municipios?busca=SAO')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['nome' => 'São Paulo', 'uf' => 'SP'])
            ->assertJsonMissing(['nome' => 'Recife']);
    }

    #[Test]
    public function prioriza_nomes_que_comecam_com_o_termo(): void
    {
        $this->criarMunicipio('Bom Jesus do Campo', 'XX');
        $this->criarMunicipio('Campinas', 'SP');

        $this->getJson('/api/municipios?busca=camp')
            ->assertOk()
            ->assertJsonPath('data.0.nome', 'Campinas');
    }

    #[Test]
    public function limita_a_dez_resultados(): void
    {
        foreach (range(1, 15) as $i) {
            $this->criarMunicipio("Santa Cidade {$i}", 'SC');
        }

        $this->getJson('/api/municipios?busca=santa')
            ->assertOk()
            ->assertJsonCount(10, 'data');
    }

    #[Test]
    public function retorna_422_quando_a_busca_e_curta_ou_ausente(): void
    {
        $this->getJson('/api/municipios?busca=a')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['busca']);

        $this->getJson('/api/municipios')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['busca']);
    }

    #[Test]
    public function seeder_popula_municipios_a_partir_do_json(): void
    {
        $this->seed(MunicipioSeeder::class);

        $this->assertGreaterThan(5000, Municipio::count());
        $this->assertDatabaseHas('municipios', [
            'nome' => 'Acrelândia',
            'nome_normalizado' => 'acrelandia',
            'uf' => 'AC',
        ]);
    }
}
```

## 8. Como rodar

```bash
php artisan migrate:fresh --seed     # cria as tabelas e importa ~5.595 municípios
php artisan test                     # esperado: 34 testes passando
php artisan serve
```

Testes manuais:

```bash
curl "http://localhost:8000/api/municipios?busca=sao jos" -H "Accept: application/json"

curl -X POST "http://localhost:8000/api/clima?cidade=Campinas" -H "Accept: application/json"   # 201, atualizado:false
curl -X POST "http://localhost:8000/api/clima?cidade=Campinas" -H "Accept: application/json"   # 200, atualizado:true (se o tempo não mudou)

curl "http://localhost:8000/api/clima/historico" -H "Accept: application/json"
curl "http://localhost:8000/api/clima/historico?cidade=campinas" -H "Accept: application/json"

curl -X POST "http://localhost:8000/api/clima" -H "Accept: application/json"                  # 422
curl -X POST "http://localhost:8000/api/clima?cidade=Xyzabc" -H "Accept: application/json"     # 404
```

Exemplo de resposta do POST:

```json
{
  "data": {
    "id": 1,
    "cidade": "Campinas",
    "temperatura": 25.5,
    "sensacao_termica": 26.1,
    "umidade": 60,
    "descricao": "céu limpo",
    "consultado_em": "2026-10-03T12:00:00.000000Z"
  },
  "atualizado": false
}
```
