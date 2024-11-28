<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Partida;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Http\Request;

use function PHPSTORM_META\map;

class AdminController extends Controller
{
    public function partidasUsuarios(Request $request)
    {
        $partidas = Partida::query()
            ->with('user')
            ->get();

        if ($partidas->isEmpty())
        {
            return response()->json([
                'message' => 'No hay partidas disponibles'
            ], 404);
        }

        $data = $partidas->map(function ($partida) {
            return [
                'id' => $partida->id,
                'user_id' => $partida->user_id,
                'user' => $partida->user->name,
                'user_email' => $partida->user->email,
                'palabra' => $partida->palabra,
                'estado' => $partida->estado,
            ];
        });

        return response()->json($data);
    }

    public function desactivarJugador(Request $request)
    {
        $validate = Validator::make($request->all(), [
            'email' => 'required|email',
        ]);

        if ($validate->fails())
        {
            return response()->json([
                'status' => 'error',
                'message' => $validate->errors()
            ], 400);
        }

        $user = User::where('email', $request->email)->first();

        if (!$user)
        {
            return response()->json([
                'message' => 'Usuario no encontrado'
            ], 404);
        }

        $user->role = 'desactivado';
        $user->save();

        return response()->json([
            'message' => 'Usuario desactivado'
        ]);
    }
}
