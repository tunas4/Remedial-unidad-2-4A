<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
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
            return response()->json([
                'message' => 'Partida no encontrada'
            ], 404);
        }

        $palabra = Letra::query()
            ->where('partida_id', $partida->id)
            ->where('palabra', $request->palabra)
            ->exists();

        if ($palabra)
        {
            return response()->json([
                'message' => 'Palabra ya usada'
            ], 400);
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
                'regex:/^[a-záéíóúñ]+$/iu',
            ],
        ]);

        if ($validate->fails()) 
        {
            return response()->json(['errors' => $validate->errors()], 422);
        }

        $letrasUsadas = Letra::query()
            ->where('palabra', $request->letra)
            ->where('partida_id', $partida->id)
            ->exists();

        if ($letrasUsadas)
        {
            return response()->json([
                'message' => 'Palabra ya usada'
            ], 400);
        }

        $palabrasUsadas = Letra::query()
            ->where('partida_id', $partida->id)
            ->count();
        
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

                if ($contadorCorrecto == count($palabraAdivinar))
                {
                    $sid    = env('SID_TWILIO');
                    $token  = env('TOKEN_TWILIO');
                    $twilio = new Client($sid, $token);

                    $message = $twilio->messages->create(
                        "whatsapp:+5212228996530",
                        array(
                            "from" => "whatsapp:+14155238886",
                            "body" => "Ganaste\nLa palabra era: " . implode('', $palabraAdivinar)
                        )
                    );

                    $partida->estado = 'ganada';
                    $partida->save();

                    return response()->json([
                        'message' => 'Ganaste'
                    ]);
                }
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

        $sid    = env('SID_TWILIO');
        $token  = env('TOKEN_TWILIO');
        $twilio = new Client($sid, $token);

        $message = $twilio->messages->create(
            "whatsapp:+5212228996530",
            array(
                "from" => "whatsapp:+14155238886",
                "body" => "Palabra incorrecta\n" . implode("\n", $arregloMensaje)
            )
        );

        $cantPalabrasUsadas = Letra::query()
            ->where('partida_id', $partida->id)
            ->count();

        $intentosRestantes = $intentosMaximos - $cantPalabrasUsadas;

        if ($intentosRestantes == 0)
        {
            $partida->estado = 'perdida';
            $partida->save();

            $sid    = env('SID_TWILIO');
            $token  = env('TOKEN_TWILIO');
            $twilio = new Client($sid, $token);

            $message = $twilio->messages->create(
                "whatsapp:+5212228996530",
                array(
                    "from" => "whatsapp:+14155238886",
                    "body" => "Perdiste, has llegado al limite de intentos\nLa palabra era: " . implode('', $palabraAdivinar)
                )
            );

            return response()->json([
                'message' => 'Perdiste, has llegado al limite de intentos'
            ], 400);
        }

        return response()->json([
            'intentosRestantes' => $intentosRestantes,
            'message' => $arregloMensaje
        ]);
    }
}
