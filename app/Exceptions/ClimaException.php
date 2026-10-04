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