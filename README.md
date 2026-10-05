# WeatherApp

Aplicação web em Laravel e React para consultar o clima de cidades brasileiras pela API do OpenWeatherMap e salvar os resultados em um banco SQLite.

O histórico permite acompanhar as consultas já feitas, filtrar por cidade e período e ver a evolução da temperatura de cada cidade. O projeto foi desenvolvido para o teste prático da Loop Sistemas.

## Funcionalidades

- Consulta de temperatura, sensação térmica, umidade, velocidade do vento e descrição do tempo.
- Sugestões de municípios durante a digitação (autocomplete).
- Histórico paginado, da consulta mais recente para a mais antiga.
- Filtros por cidade e período.
- Painel da cidade selecionada com mínima, média e máxima, além de um gráfico da variação de temperatura.
- Cancelamento de uma consulta em andamento.
- Aviso quando a consulta repete as mesmas condições e apenas atualiza o registro existente.
- Cache do histórico e da lista de cidades consultadas.
- Tratamento de falhas da API externa e de validação, com mensagens claras em português.

Durante uma consulta, o botão de envio fica bloqueado e o botão Cancelar é exibido para interromper a requisição.

## Tecnologias

- PHP 8.3+ e Laravel 13
- SQLite
- OpenWeatherMap (Current Weather Data)
- React 19, Vite e Tailwind CSS 4
- PHPUnit 12

## Como executar

### Pré-requisitos

- PHP 8.3 ou superior, com a extensão `pdo_sqlite`.
- Composer.
- Node.js e npm.
- Uma chave da API do OpenWeatherMap.

A consulta meteorológica precisa de acesso à internet. A busca de municípios e o histórico usam apenas o banco local.

### 1. Instalar as dependências

Na raiz do repositório:

```sh
composer install
npm install
```

### 2. Configurar o ambiente

Copie o arquivo de exemplo e gere a chave da aplicação:

```sh
cp .env.example .env
php artisan key:generate
```

Adicione ao `.env` a configuração do OpenWeatherMap:

```env
OPENWEATHER_API_KEY=SUA_CHAVE_AQUI
OPENWEATHER_BASE_URL=https://api.openweathermap.org/data/2.5
OPENWEATHER_TIMEOUT=5
```

Apenas `OPENWEATHER_API_KEY` é obrigatória. As outras duas já têm esses valores como padrão em `config/services.php`.

O `.env` está no `.gitignore`, pois contém a configuração particular de quem executa o projeto.

### 3. Criar o banco

O projeto usa `DB_CONNECTION=sqlite`, que por padrão grava em `database/database.sqlite`. Crie o arquivo, rode as migrations e o seeder dos municípios:

```sh
touch database/database.sqlite
php artisan migrate --seed
```

O seeder lê o arquivo `cidades.json` e preenche a tabela `municipios`. Ele pode ser executado novamente sem duplicar registros, pois limpa a tabela antes de inserir.

### 4. Iniciar

Em dois terminais, na raiz do repositório:

```sh
php artisan serve
```

```sh
npm run dev
```

Acesse `http://localhost:8000`.

Para usar os arquivos compilados em vez do servidor do Vite, rode `npm run build` e depois apenas `php artisan serve`.

## Como usar

1. Digite pelo menos duas letras do nome de uma cidade.
2. Escolha uma das sugestões.
3. Clique em **Consultar**. Se quiser interromper, clique em **Cancelar**.
4. O resultado é salvo e aparece selecionado no histórico, com os detalhes no painel ao lado.

Se as condições da cidade não mudaram desde a última consulta, a aplicação avisa que apenas a data e a hora do registro foram atualizadas.

Para filtrar o histórico, escolha uma cidade e/ou um período (De / Até) e clique em **Filtrar**. **Limpar** remove os filtros. O botão **Recarregar** busca novamente os registros.

Ao clicar em uma linha do histórico, o painel mostra a temperatura atual daquela cidade, a mínima, a média e a máxima do período e o gráfico com a variação das consultas.

## API

Todas as rotas ficam sob o prefixo `/api` e respondem em JSON.

| Método | Rota | Parâmetros | Descrição |
| --- | --- | --- | --- |
| GET | `/api/municipios` | `busca` (2 a 100 caracteres) | Sugere até 5 municípios para o autocomplete. |
| POST | `/api/clima` | `cidade` (2 a 100 caracteres) | Consulta o OpenWeatherMap e grava ou atualiza o registro. |
| GET | `/api/clima/historico` | `cidade`, `de`, `ate`, `page`, `per_page` (máx. 50) | Lista o histórico paginado, com filtros opcionais. |
| GET | `/api/clima/cidades` | — | Lista as cidades que já foram consultadas. |

O `POST /api/clima` retorna `201` quando cria um registro e `200` quando apenas atualiza um existente. O campo `atualizado` da resposta indica qual dos dois aconteceu.

Exemplo:

```sh
curl -X POST "http://localhost:8000/api/clima?cidade=Jales" -H "Accept: application/json"
```

## Organização do código

O back-end segue o fluxo Controller → Service → Provider / Repository:

| Pasta | Responsabilidade |
| --- | --- |
| `app/Http/Controllers` | Recebem a requisição e devolvem a resposta HTTP. |
| `app/Http/Requests` | Validação dos parâmetros e mensagens de erro. |
| `app/Http/Resources` | Formato de saída dos municípios. |
| `app/Services` | Regras de negócio: quando criar ou atualizar, cache do histórico, normalização da busca. |
| `app/Providers/Weather` | Comunicação com o OpenWeatherMap, atrás da interface `WeatherProviderInterface`. |
| `app/Repositories` | Leitura e gravação no banco. |
| `app/DTOs` | `DadosClima`, os dados meteorológicos já convertidos da resposta da API. |
| `app/Exceptions` | Exceções de domínio, cada uma com seu status HTTP. |
| `app/Models` | Models Eloquent `ConsultaClima` e `Municipio`. |

Os controllers não acessam o banco nem fazem chamadas HTTP diretamente. A implementação do provider é registrada no `AppServiceProvider`, o que permite trocar o OpenWeatherMap por outro serviço sem alterar o service.

O front-end fica em `resources/js`:

| Pasta | Responsabilidade |
| --- | --- |
| `api.js` | Chamadas à API e tradução das respostas de erro em mensagens. |
| `components` | Barra de busca, filtros, tabela, paginação, painel e gráfico. |
| `hooks` | `useRequest`, que cancela a requisição anterior ao mudar os parâmetros, e `useAutocomplete`, com debounce de 300 ms. |
| `utils` | Formatação de datas e temperaturas e escolha do ícone pela condição do tempo. |

## Decisões técnicas

### SQLite

O projeto tem poucas tabelas e não precisa de um servidor de banco separado. O SQLite deixa a configuração simples e também é usado em memória nos testes.

### Critério para salvar o histórico

A aplicação não grava registros redundantes. Ao receber os dados da API, o service busca a última consulta salva daquela cidade e compara:

- temperatura;
- sensação térmica;
- umidade;
- descrição do tempo.

Se todos forem iguais, o registro existente é atualizado com a nova data e hora de consulta. Se algum valor mudou, ou se ainda não existe registro da cidade, é criado um novo.

A velocidade do vento não entra na comparação: ela é atualizada no registro existente, mas sozinha não gera uma nova linha.

As cidades são identificadas por um slug do nome devolvido pela API (`São Paulo` → `sao-paulo`), o que torna a comparação e o filtro independentes de acentos e maiúsculas.

### Cache do histórico

O histórico e a lista de cidades consultadas ficam em cache por 10 minutos. Cada combinação de filtros e página tem sua própria chave.

Em vez de apagar chave por chave, as chaves incluem um número de versão. Toda gravação, seja criação ou atualização, incrementa essa versão, e as consultas seguintes passam a usar chaves novas. Assim, o histórico nunca mostra dados desatualizados depois de uma consulta.

### Sugestões de municípios

Os municípios vêm do arquivo `cidades.json`, importado pelo seeder com o nome original, a UF e um nome normalizado (sem acentos e em minúsculas). A busca aplica a mesma normalização ao termo, procura nomes que contenham o texto e prioriza os que começam com ele.

O catálogo serve apenas para sugerir nomes. Os dados meteorológicos continuam vindo do OpenWeatherMap.

### Horários

As datas são armazenadas em UTC. O front-end envia os filtros de período em ISO 8601 e exibe os horários no fuso local do navegador. O filtro inclui o minuto final informado.

### Tratamento de erros

Falhas da API externa são convertidas em exceções de domínio, que respondem com uma mensagem objetiva no campo `erro`:

| Situação | Status |
| --- | --- |
| Parâmetro ausente ou inválido | 422 |
| Cidade não encontrada no OpenWeatherMap | 404 |
| Chave da API inválida ou não configurada | 502 |
| Erro inesperado ou resposta em formato inválido | 502 |
| Limite de requisições atingido | 503 |
| OpenWeatherMap inacessível ou tempo limite excedido | 503 |

As falhas também são registradas no log do Laravel (`storage/logs/laravel.log`), sem incluir a chave da API.

### Gráfico

O gráfico de temperatura é um SVG desenhado no próprio componente React, sem biblioteca adicional. Ele precisa de pelo menos duas consultas da cidade para traçar a curva.

## Testes

Para executar todos os testes, na raiz do repositório:

```sh
php artisan test
```

Os testes usam SQLite em memória e `Http::fake()` para simular o OpenWeatherMap. Não é necessário ter uma chave real nem acesso à internet.

**Testes unitários** (`tests/Unit`):

- Criação de registro quando não existe consulta anterior.
- Apenas atualização da data quando os valores são iguais.
- Novo registro quando temperatura, sensação, umidade ou descrição mudam.
- Vento diferente não gera novo registro.
- Nenhuma gravação quando o provider falha.
- Uso do cache no histórico, chaves separadas por filtro e invalidação após gravar.
- Normalização do termo de busca de municípios.

**Testes de integração** (`tests/Feature`):

- Fluxo completo do `POST /api/clima`, com respostas 201 e 200.
- Erros 404, 422, 502 e 503.
- Conversão da velocidade do vento para km/h e vento ausente na resposta.
- Histórico ordenado, filtros por cidade e período, conversão de fuso, paginação e invalidação do cache.
- Seeder dos municípios e busca do autocomplete, ignorando acentos e maiúsculas.

## Limitações

- A busca na API usa o nome da cidade e o país (`Cidade,BR`), sem a UF. Municípios com o mesmo nome em estados diferentes podem retornar o mesmo resultado. Uma melhoria seria consultar por coordenadas.
- A comparação para evitar duplicados olha apenas o último registro da cidade. Se as condições mudarem e depois voltarem ao valor anterior, um novo registro é criado, o que é o comportamento esperado para o histórico.
- O cache usa o driver configurado no `.env` (por padrão, `database`). Em produção, um driver como Redis seria mais adequado.
- A API não tem autenticação nem limite de requisições próprio.

## Uso de IA
link da conversa: 
O desenvolvimento contou com apoio de IA (Claude Code) no planejamento, na revisão do código, na criação de testes e na documentação

O plano do back-end foi registrado em `implementacao.md tarefa-parte1.md` e o do front-end em `tarefa-parte2.md`, com a estrutura de cada arquivo e uma breve descrição. A implementação foi feita manualmente a partir desses planos, com ajustes ao longo do caminho.
