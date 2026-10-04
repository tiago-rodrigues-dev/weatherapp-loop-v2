<?php

namespace App\Http\Controllers\Municipio;

use App\Http\Controllers\Controller;
use App\Http\Requests\Municipio\BuscarMunicipioRequest;
use App\Http\Resources\Municipio\MunicipioResource;
use App\Services\Municipio\MunicipioService;
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
