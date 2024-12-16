<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Jobs\slack;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Laravel\Sanctum\PersonalAccessToken;
use App\Models\Partida;
use App\Models\Letra;
use Twilio\Rest\Client;

class WordleController extends Controller
{
    public function jugar(Request $request)
    {
        $intentosMaximos = env('INTENTOS');
        $token = $request->bearerToken();
        $accessToken = PersonalAccessToken::findToken($token);
        $user = $accessToken->tokenable;

        $partida = Partida::query()
            ->where('user_id', $user->id)
            ->whereIn('estado', ['jugando'])
            ->first();

        if (!$partida) 
        {
            return response()->json(['message' => 'Partida no encontrada'], 404);
        }

        $palabra = Letra::query()
            ->where('partida_id', $partida->id)
            ->where('palabra', $request->palabra)
            ->exists();

        if ($palabra) 
        {
            return response()->json(['message' => 'Palabra ya usada'], 400);
        }

        $longitudPalabra = Partida::query()
            ->where('user_id', $user->id)
            ->where('estado', 'jugando')
            ->value('longitud');

        $validate = Validator::make($request->all(), [
            'palabra' => [
                'required',
                'string',
                'min:' . $longitudPalabra,
                'max:' . $longitudPalabra,
                'regex:/^[a-z]+$/iu',
            ],
        ]);

        if ($validate->fails()) 
        {
            return response()->json([
                'errors' => $validate->errors()
            ], 422);
        }

        $palabraAdivinar = str_split($partida->palabra);
        $letras = str_split($request->palabra);

        $arregloMensaje = [];
        $contadorCorrecto = 0;

        for ($i = 0; $i < count($palabraAdivinar); $i++) 
        {
            if ($letras[$i] == $palabraAdivinar[$i]) 
            {
                $arregloMensaje[] = 'La letra ' . $letras[$i] . ' es correcta';
                $contadorCorrecto++;
            } 
            else if (in_array($letras[$i], $palabraAdivinar)) 
            {
                $arregloMensaje[] = 'La letra ' . $letras[$i] . ' está en la palabra pero en una posición incorrecta';
            } 
            else 
            {
                $arregloMensaje[] = 'La letra ' . $letras[$i] . ' no está en la palabra';
            }
        }

        Letra::create([
            'partida_id' => $partida->id,
            'palabra' => $request->palabra,
        ]);

        $cantPalabrasUsadas = Letra::query()
            ->where('partida_id', $partida->id)
            ->count();

        $intentosRestantes = $intentosMaximos - $cantPalabrasUsadas;

        $palabrasUsadas = Letra::query()
            ->where('partida_id', $partida->id)
            ->get();

        $progresoPalabra = array_fill(0, count($palabraAdivinar), '_');

        foreach ($palabrasUsadas as $palabraIntento) 
        {
            $letrasIntento = str_split($palabraIntento->palabra);
            for ($i = 0; $i < count($palabraAdivinar); $i++) 
            {
                if (isset($letrasIntento[$i]) && $letrasIntento[$i] === $palabraAdivinar[$i]) 
                {
                    $progresoPalabra[$i] = $letrasIntento[$i];
                }
            }
        }

        $resumen = "Resumen de la partida:\n";
        $resumen .= "Estado: " . ($contadorCorrecto == count($palabraAdivinar) ? 'Ganada' : 'Perdida') . "\n";
        $resumen .= "Progreso actual: " . implode(' ', $progresoPalabra) . "\n";
        $resumen .= "Intentos restantes: {$intentosRestantes}\n";
        $resumen .= "Palabras usadas:\n";

        foreach ($palabrasUsadas as $palabraIntento) 
        {
            $resumen .= "- " . $palabraIntento->palabra . "\n";
        }

        if ($contadorCorrecto == count($palabraAdivinar)) 
        {
            $partida->estado = 'ganada';
            $partida->save();

            dispatch(new slack($resumen))->delay(now()->addSeconds(60));

            return response()->json(['message' => 'Ganaste']);
        }

        if ($intentosRestantes == 0) 
        {
            $partida->estado = 'perdida';
            $partida->save();

            dispatch(new slack($resumen))->delay(now()->addSeconds(60));

            return response()->json(['message' => 'Perdiste, has llegado al limite de intentos'], 400);
        }

        return response()->json([
            'intentosRestantes' => $intentosRestantes,
            'message' => $arregloMensaje,
        ]);
    }
}
