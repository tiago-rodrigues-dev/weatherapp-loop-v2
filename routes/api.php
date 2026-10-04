<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Clima\ClimaController;
use App\Http\Controllers\Municipio\MunicipioController;

Route::get('/municipios', [MunicipioController::class, 'index']);

Route::post('/clima', [ClimaController::class, 'store']);
Route::get('/clima/historico', [ClimaController::class, 'historico']);