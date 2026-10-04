<?php

namespace App\Interfaces;

use App\DTOs\DadosClima;
use App\Exceptions\ClimaException;

interface WeatherProviderInterface
{
    /**
     * @throws ClimaException
     */
    public function buscarClimaAtual(string $cidade): DadosClima;
}