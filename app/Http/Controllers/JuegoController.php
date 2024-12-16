<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Jobs\slack;
use App\Models\Letra;
use App\Models\Partida;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\PersonalAccessToken;
use Illuminate\Support\Facades\Validator;
use Twilio\Rest\Client;

class JuegoController extends Controller
{
    public function crearPartida(Request $request)
    {
        $token = $request->bearerToken();

        $accessToken = PersonalAccessToken::findToken($token);

        $user = $accessToken->tokenable;

        $response = Http::get('https://clientes.api.greenborn.com.ar/public-random-word');
        
        $palabra = trim($response->body(), '[]" ');

        $palabra = str_replace(
            ['á', 'é', 'í', 'ó', 'ú', 'ü', 'ñ'],
            ['a', 'e', 'i', 'o', 'u', 'u', 'n'],
            $palabra
        );

        $longitud = strlen($palabra);

        $partida = Partida::create([
            'user_id' => $user->id,
            'palabra' => $palabra,
            'longitud' => $longitud,
            'estado' => 'por empezar',
        ]);

        return response()->json([
            'message' => 'Partida creada con éxito',
            'longitud' => 'Longitud de la palabra: ' . $longitud
        ], 201);
    }

    public function obtenerPartidas(Request $request)
    {
        $token = $request->bearerToken();

        $accessToken = PersonalAccessToken::findToken($token);

        $user = $accessToken->tokenable;

        $partida = Partida::query()
            ->where('user_id', $user->id)
            ->whereIn('estado', ['por empezar'])->get();

        if ($partida->count() == 0)
        {
            return response()->json([
                'message' => 'No hay partidas disponibles'
            ], 404);
        }

        $partidaId = $partida->pluck('id');

        return response()->json([
            'partidas' => $partidaId
        ], 200);
    }

    public function unirsePartida(Request $request, $partidaId)
    {
        $token = $request->bearerToken();

        $accessToken = PersonalAccessToken::findToken($token);

        $user = $accessToken->tokenable;

        $partidaEnCurso = Partida::query()
            ->where('user_id', $user->id)
            ->whereIn('estado', ['jugando'])
            ->exists();

        if ($partidaEnCurso) 
        {
            return response()->json([
                'message' => 'Ya tienes una partida en curso'
            ], 400);
        }

        $partida = Partida::find($partidaId);

        if (!$partida || $partida->user_id != $user->id) 
        {
            return response()->json([
                'message' => 'Partida no encontrada'
            ], 404);
        }

        if ($partida->estado !== 'por empezar') 
        {
            return response()->json([
                'message' => 'Partida no disponible'
            ], 400);
        }

        $partida->estado = 'jugando';
        $partida->save();

        return response()->json([
            'message' => 'Te has unido a la partida con éxito',
        ], 200);
    }

    public function jugar(Request $request)
    {
        $intentosMaximos = env('INTENTOS');
    
        $token = $request->bearerToken();
    
        $accessToken = PersonalAccessToken::findToken($token);
    
        $user = $accessToken->tokenable;
    
        $partida = Partida::query()
            ->where('user_id', $user->id)
            ->where('estado', 'jugando')
            ->first();
    
        if (!$partida) 
        {
            return response()->json([
                'message' => 'Partida no encontrada'
            ], 404);
        }
    
        $validate = Validator::make($request->all(), [
            'letra' => 'required|string|min:1|max:1|regex:/^[a-z]$/u',
        ]);
    
        if ($validate->fails()) 
        {
            return response()->json([
                'errors' => $validate->errors()
            ], 422);
        }
    
        $letra = $request->letra;
    
        $letrasUsadas = Letra::query()
            ->where('letra', $letra)
            ->where('partida_id', $partida->id)
            ->exists();
    
        if ($letrasUsadas) 
        {
            return response()->json([
                'message' => 'Letra ya usada'
            ], 400);
        }
    
        $palabra = $partida->palabra;
    
        $arregloPalabra = str_split($palabra);
    
        $letrasIncorrectas = Letra::query()
            ->where('partida_id', $partida->id)
            ->whereNotIn('letra', $arregloPalabra)
            ->count();
    
        $intentosRestantes = $intentosMaximos - $letrasIncorrectas;
    
        if (!str_contains($palabra, $letra)) 
        {
            Letra::create([
                'partida_id' => $partida->id,
                'letra' => $letra,
            ]);
    
            $intentosRestantes--;
    
            if ($intentosRestantes <= 0) 
            {
                $letrasUsadas = Letra::query()
                    ->where('partida_id', $partida->id)
                    ->pluck('letra')
                    ->toArray();
    
                $palabraProgreso = $this->revelarPalabra($arregloPalabra, $letrasUsadas);
    
                $letrasIncorrectas = array_filter($letrasUsadas, function ($letra) use ($arregloPalabra) 
                {
                    return !in_array($letra, $arregloPalabra);
                });
    
                $letrasCorrectas = array_filter($letrasUsadas, function ($letra) use ($arregloPalabra) 
                {
                    return in_array($letra, $arregloPalabra);
                });
    
                $resumen = "Resumen de la partida:\n" .
                    "Palabra: $palabraProgreso\n" .
                    "Intentos restantes: $intentosRestantes\n" .
                    "Letras incorrectas: " . implode(', ', $letrasIncorrectas) . "\n" .
                    "Letras correctas: " . implode(', ', $letrasCorrectas);
    
                $partida->update(['estado' => 'perdida']);

                dispatch(new slack($resumen))->delay(now()->addSeconds(60));
    
                return response()->json([
                    'message' => 'Has perdido la partida.',
                    'palabra' => $palabra,
                ], 200);
            }
    
            $palabraProgreso = $this->revelarPalabra($arregloPalabra, Letra::query()
                ->where('partida_id', $partida->id)
                ->pluck('letra')
                ->toArray());
    
            $twilio = new Client(env('SID_TWILIO'), env('TOKEN_TWILIO'));
            $twilio->messages->create(
                "whatsapp:+5212228996530",
                [
                    "from" => "whatsapp:+14155238886",
                    "body" => "Letra incorrecta: $letra\nIntentos restantes: $intentosRestantes\nPalabra: $palabraProgreso",
                ]
            );
    
            return response()->json([
                'message' => 'Letra incorrecta',
                'palabraProgreso' => $palabraProgreso,
                'intentosRestantes' => $intentosRestantes
            ], 200);
        }
    
        Letra::create([
            'partida_id' => $partida->id,
            'letra' => $letra,
        ]);
    
        $letrasUsadas = Letra::query()
            ->where('partida_id', $partida->id)
            ->pluck('letra')
            ->toArray();
    
        $palabraProgreso = $this->revelarPalabra($arregloPalabra, $letrasUsadas);
    
        if (trim(str_replace(' ', '', $palabraProgreso)) === implode('', $arregloPalabra)) 
        {
            $letrasIncorrectas = array_filter($letrasUsadas, function ($letra) use ($arregloPalabra) {
                return !in_array($letra, $arregloPalabra);
            });
    
            $letrasCorrectas = array_filter($letrasUsadas, function ($letra) use ($arregloPalabra) {
                return in_array($letra, $arregloPalabra);
            });
    
            $resumen = "Resumen de la partida:\n" .
                "Palabra: $palabraProgreso\n" .
                "Intentos restantes: $intentosRestantes\n" .
                "Letras incorrectas: " . implode(', ', $letrasIncorrectas) . "\n" .
                "Letras correctas: " . implode(', ', $letrasCorrectas);
    
            $twilio = new Client(env('SID_TWILIO'), env('TOKEN_TWILIO'));
            $twilio->messages->create(
                "whatsapp:+5212228996530", [
                    "from" => "whatsapp:+14155238886",
                    "body" => "Ganaste\n$resumen",
                ]
            );
    
            $partida->update(['estado' => 'ganada']);

            dispatch(new slack($resumen))->delay(now()->addSeconds(60));
    
            return response()->json([
                'message' => 'Palabra adivinada',
                'palabra' => $palabra,
            ], 200);
        }
    
        $twilio = new Client(env('SID_TWILIO'), env('TOKEN_TWILIO'));
        $twilio->messages->create(
            "whatsapp:+5212228996530", [
                "from" => "whatsapp:+14155238886",
                "body" => "Letra correcta: $letra\nIntentos restantes: $intentosRestantes\nPalabra: $palabraProgreso",
            ]
        );
    
        return response()->json([
            'message' => 'Letra correcta',
            'palabraProgreso' => $palabraProgreso,
            'intentosRestantes' => $intentosRestantes
        ], 200);
    }

    private function revelarPalabra($arregloPalabra, $letrasUsadas)
    {
        $palabraProgreso = '';

        foreach ($arregloPalabra as $letra) 
        {
            if (in_array($letra, $letrasUsadas)) 
            {
                $palabraProgreso .= " " . $letra;
            } 
            else 
            {
                $palabraProgreso .= ' _';
            }
        }

        return $palabraProgreso;
    }

    public function progreso()
    {
        $intentosMaximos = env('INTENTOS');

        $token = request()->bearerToken();

        $accessToken = PersonalAccessToken::findToken($token);

        $user = $accessToken->tokenable;

        $partida = Partida::query()
            ->where('user_id', $user->id)
            ->whereIn('estado', ['jugando'])
            ->first();

        if (!$partida)
        {
            return response()->json([
                'message' => 'No hay partida en curso'
            ], 404);
        }

        $palabra = str_split($partida->palabra);
        $letrasAdivinadas = Letra::where('partida_id', $partida->id)->pluck('letra')->toArray();

        $palabraProgreso = $this->revelarPalabra($palabra, $letrasAdivinadas);

        $letrasIncorrectas = array_filter($letrasAdivinadas, function ($letra) use ($palabra) {
            return !in_array($letra, $palabra);
        });

        $letrasCorrectas = array_filter($letrasAdivinadas, function ($letra) use ($palabra) {
            return in_array($letra, $palabra);
        });

        $intentosRestantes = $intentosMaximos - count($letrasIncorrectas);

        $sid    = env('SID_TWILIO');
        $token  = env('TOKEN_TWILIO');
        $twilio = new Client($sid, $token);

        $message = $twilio->messages->create(
            "whatsapp:+5212228996530",
            array(
                "from" => "whatsapp:+14155238886",
                "body" => "
                    Progreso
                    \nPalabra: $palabraProgreso
                    \nIntentos restantes: $intentosRestantes
                    \nLetras incorrectas: " . implode(', ', $letrasIncorrectas) . 
                    "\nLetras correctas: " . implode(', ', $letrasCorrectas)
            )
        );

        return response()->json([
            'palabraProgreso' => $palabraProgreso,
            'intentosRestantes' => $intentosRestantes,
            'letrasCorrectas' => $letrasCorrectas,
            'letrasIncorrectas' => $letrasIncorrectas
        ]);
    }

    public function historialJuegos(Request $request)
    {
        $token = $request->bearerToken();

        $accessToken = PersonalAccessToken::findToken($token);

        $user = $accessToken->tokenable;

        $partidas = Partida::query()
            ->where('user_id', $user->id)
            ->whereIn('estado', ['ganada', 'perdida', 'cancelada'])
            ->get();

        if ($partidas->isEmpty())
        {
            return response()->json([
                'message' => 'No hay partidas disponibles'
            ], 404);
        }

        return response()->json([
            'partidas' => $partidas->map(function ($partida) {
                return [
                    'id' => $partida->id,
                    'palabra' => $partida->palabra,
                    'estado' => $partida->estado,
                ];
            })
        ], 200);
    }

    public function abandonarPartida(Request $request)
    {
        $token = $request->bearerToken();

        $accessToken = PersonalAccessToken::findToken($token);

        $user = $accessToken->tokenable;

        $partida = Partida::query()
            ->where('user_id', $user->id)
            ->whereIn('estado', ['jugando'])
            ->first();

        if (!$partida || $partida->user_id != $user->id) 
        {
            return response()->json([
                'message' => 'Partida no encontrada'
            ], 404);
        }

        $letrasUsadas = Letra::query()
            ->where('partida_id', $partida->id)
            ->pluck('letra')
            ->toArray();

        $arregloPalabra = str_split($partida->palabra);

        $letrasIncorrectas = array_filter($letrasUsadas, function ($letra) use ($arregloPalabra) {
            return !in_array($letra, $arregloPalabra);
        });

        $letrasCorrectas = array_filter($letrasUsadas, function ($letra) use ($arregloPalabra) {
            return in_array($letra, $arregloPalabra);
        });

        $resumen = "Resumen de la partida:\n" .
            "Palabra: " . $this->revelarPalabra($arregloPalabra, $letrasUsadas) . "\n" .
            "Letras incorrectas: " . implode(', ', $letrasIncorrectas) . "\n" .
            "Letras correctas: " . implode(', ', $letrasCorrectas);

        $partida->estado = 'perdida';
        $partida->save();

        dispatch(new slack($resumen))->delay(now()->addSeconds(60));

        return response()->json([
            'message' => 'Partida abandonada',
        ], 200);
    }
}
