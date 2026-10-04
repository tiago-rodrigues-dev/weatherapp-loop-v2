<?php

namespace App\Http\Controllers\Municipio;

use App\Services\Municipio\MunicipioService;
use Illuminate\Http\Request;
use App\Http\Requests\BuscarMunicipioRequest;
use App\Http\Resources\Municipio\MunicipioResource;
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
