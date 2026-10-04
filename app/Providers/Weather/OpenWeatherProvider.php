<?php

namespace App\Providers\Weather;

use App\DTOs\DadosClima;
use App\Exceptions\CidadeNaoEncontradaException;
use App\Exceptions\FalhaApiClimaException;
use App\Exceptions\ServicoClimaIndisponivelException;
use App\Interfaces\WeatherProviderInterface;
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
            ventoKmh: isset($dados['wind']['speed']) ? round($dados['wind']['speed'] * 3.6, 1) : null,
        );
    }
}