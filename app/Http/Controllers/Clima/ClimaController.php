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
        return response()->json([
            'data' => $this->service->historico($request->validated('cidade')),
        ]);
    }
}