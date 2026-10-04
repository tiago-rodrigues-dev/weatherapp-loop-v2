<?php

namespace App\Services\Clima;

use App\Models\Clima\ConsultaClima;

final readonly class ResultadoRegistroClima
{
    public function __construct(
        public ConsultaClima $consulta,
        public bool $atualizado,
    ) {}
}