<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
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
        ]);
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
            ->whereIn('estado', ['jugando'])
            ->first();

        if (!$partida)
        {
            return response()->json([
                'message' => 'Partida no encontrada'
            ], 404);
        }

        $letrasUsadas = Letra::query()
            ->where('letra', $request->letra)
            ->where('partida_id', $partida->id)
            ->exists();

        $validate = Validator::make($request->all(), [
            'letra' => 'required|string|min:1|max:1|regex:/^[a-záéíóúñ]$/u',
        ]);

        if ($validate->fails())
        {
            return response()->json([
                'errors' => $validate->errors()
            ], 400);
        }

        if ($letrasUsadas)
        {
            return response()->json([
                'message' => 'Letra ya usada'
            ], 400);
        }

        $palabra = $partida->palabra;
        $arregloPalabra = str_split($palabra);

        $letrasUsadas = Letra::query()
            ->where('partida_id', $partida->id)
            ->pluck('letra')
            ->toArray();

        $intentosRestantes = $intentosMaximos - count(
            Letra::query()
                ->where('partida_id', $partida->id)
                ->whereNotIn('letra', str_split($palabra))
                ->get()
        );

        if ($intentosRestantes <= 0)
        {
            $sid    = env('SID_TWILIO');
            $token  = env('TOKEN_TWILIO');
            $twilio = new Client($sid, $token);

            $message = $twilio->messages->create(
                "whatsapp:+5212228996530",
                array(
                    "from" => "whatsapp:+14155238886",
                    "body" => "Perdiste\nPalabra: " . $palabra,
                )
            );

            $partida->update(['estado' => 'perdida']);
            return response()->json([
                'message' => 'Has perdido la partida.',
                'palabra' => $palabra
            ], 400);
        }

        if (!str_contains($palabra, $request->letra))
        {
            Letra::create([
                'partida_id' => $partida->id,
                'letra' => $request->letra,
            ]);

            $intentosRestantes--;

            $sid    = env('SID_TWILIO');
            $token  = env('TOKEN_TWILIO');
            $twilio = new Client($sid, $token);

            $palabraProgreso = $this->revelarPalabra($arregloPalabra, $letrasUsadas);

            $message = $twilio->messages->create(
                "whatsapp:+5212228996530",
                array(
                    "from" => "whatsapp:+14155238886",
                    "body" => "Letra incorrecta: $request->letra\nIntentos restantes: $intentosRestantes\nPalabra: " . $palabraProgreso, "intentos_restantes" => $intentosRestantes
                )
            );

            return response()->json([
                'message' => 'Letra incorrecta',
                'palabraProgreso' => $this->revelarPalabra($arregloPalabra, $letrasUsadas),
                'intentosRestantes' => $intentosRestantes
            ], 400);
        }
        else
        {
            Letra::create([
                'partida_id' => $partida->id,
                'letra' => $request->letra,
            ]);

            $sid    = env('SID_TWILIO');
            $token  = env('TOKEN_TWILIO');
            $twilio = new Client($sid, $token);

            $letrasUsadas[] = $request->letra;
            $palabraProgreso = $this->revelarPalabra($arregloPalabra, $letrasUsadas);

            $message = $twilio->messages->create(
                "whatsapp:+5212228996530",
                array(
                    "from" => "whatsapp:+14155238886",
                    "body" => "Letra correcta: $request->letra\nIntentos restantes: $intentosRestantes\nPalabra: $palabraProgreso",
                    "intentos_restantes" => $intentosRestantes
                )
            );

            $palabraAdivinarArray = str_split($partida->palabra);

            $palabraAdivinar = '';

            foreach ($palabraAdivinarArray as $letra)
            {
                $palabraAdivinar .= ' ' . $letra;
            }

            if ($palabraProgreso == $palabraAdivinar) 
            {
                $sid    = env('SID_TWILIO');
                $token  = env('TOKEN_TWILIO');
                $twilio = new Client($sid, $token);

                $message = $twilio->messages->create(
                    "whatsapp:+5212228996530",
                    array(
                        "from" => "whatsapp:+14155238886",
                        "body" => "Ganaste\nPalabra: " . $palabra,
                    )
                );

                $partida->update(['estado' => 'ganada']);
                return response()->json([
                    'message' => '¡Felicidades! Has adivinado la palabra.',
                    'palabra' => $palabra,
                    'intentos_restantes' => $intentosRestantes
                ], 200);
            }

            return response()->json([
                'message' => 'Letra correcta',
                'palabraProgreso' => $palabraProgreso,
                'intentosRestantes' => $intentosRestantes
            ], 200);
        }
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
        ]);
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
    
        $partida->estado = 'perdida';
        $partida->save();

        $sid    = env('SID_TWILIO');
        $token  = env('TOKEN_TWILIO');
        $twilio = new Client($sid, $token);

        $message = $twilio->messages->create(
            "whatsapp:+5212228996530",
            array(
                "from" => "whatsapp:+14155238886",
                "body" => "Palabra: " . $partida->palabra,
            )
        );
    
        return response()->json([
            'message' => 'Partida abandonada',
        ], 200);
    }
}
