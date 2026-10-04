<?php

namespace App\Exceptions;

class FalhaApiClimaException extends ClimaException
{
    public function status(): int
    {
        return 502;
    }
}