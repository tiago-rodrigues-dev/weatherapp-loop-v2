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