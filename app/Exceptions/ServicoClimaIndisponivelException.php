<?php

namespace App\Exceptions;

class ServicoClimaIndisponivelException extends ClimaException
{
    public function status(): int
    {
        return 503;
    }
}