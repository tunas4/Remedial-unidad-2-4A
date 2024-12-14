<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/


Route::prefix('v1')->group(function () 
{
    Route::post('registro', [App\Http\Controllers\AuthController::class, 'register']);
    Route::post('activar-cuenta', [App\Http\Controllers\AuthController::class, 'activateAccount']);
    Route::post('iniciar-sesion', [App\Http\Controllers\AuthController::class, 'login']);
    
    Route::post('partida', [App\Http\Controllers\JuegoController::class, 'crearPartida'])
        ->middleware('jugador');
    Route::get('partida', [App\Http\Controllers\JuegoController::class, 'obtenerPartidas'])
        ->middleware('jugador');
    Route::post('unirse/{partidaId}', [App\Http\Controllers\JuegoController::class, 'unirsePartida'])
        ->middleware('jugador');

    Route::get('progreso', [App\Http\Controllers\JuegoController::class, 'progreso'])
        ->middleware('jugador');

    Route::get('historial', [App\Http\Controllers\JuegoController::class, 'historialJuegos'])
        ->middleware('jugador');

    Route::post('abandonar', [App\Http\Controllers\JuegoController::class, 'abandonarPartida'])
        ->middleware('jugador');

    Route::get('usuarios-partidas', [App\Http\Controllers\AdminController::class, 'partidasUsuarios'])
        ->middleware('admin');

    Route::post('desactivar-jugador', [App\Http\Controllers\AdminController::class, 'desactivarJugador'])
        ->middleware('admin');

    Route::post('wordle', [App\Http\Controllers\WordleController::class, 'jugar'])
        ->middleware('jugador');
});
