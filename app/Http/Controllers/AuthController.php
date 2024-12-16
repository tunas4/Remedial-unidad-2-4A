<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Codigo;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Twilio\Rest\Client;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $validate = Validator::make($request->all(), [
            'name' => 'required|regex:/^[a-zA-Z\s]+$/u|max:255',
            'email' => 'required|email|unique:users|max:255',
            'password' => [
                'required',
                'string',
                'min:8',
                'regex:/^[A-Za-z\d@$!%*?&]+$/',
            ],
        ]);

        if ($validate->fails()) 
        {
            return response()->json([
                'status' => 'error',
                'message' => $validate->errors()
            ], 422);
        }

        $sid    = env('SID_TWILIO');
        $token  = env('TOKEN_TWILIO');
        $twilio = new Client($sid, $token);

        $codigo = rand(100000, 999999);

        $message = $twilio->messages->create(
            "whatsapp:+5212228996530",
            [
                "from" => "whatsapp:+14155238886",
                "body" => "Tu código de verificación es: $codigo"
            ]
        );

        if (!$message) 
        {
            return response()->json([
                'message' => 'Error al enviar el codigo de verificacion'
            ], 400);
        }

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => bcrypt($request->password),
        ]);

        if (!$user) 
        {
            return response()->json([
                'message' => 'Error al crear el usuario'
            ], 400);
        }

        Codigo::create([
            'user_id' => $user->id,
            'codigo' => Hash::make($codigo),
        ]);

        return response()->json([
            'message' => 'Se envio un codigo de verificacion'
        ], 201);
    }

    public function activateAccount(Request $request)
    {
        $validate = Validator::make($request->all(), [
            'codigo' => 'required|integer',
        ]);

        if ($validate->fails()) 
        {
            return response()->json([
                'status' => 'error',
                'message' => $validate->errors()
            ], 422);
        }

        $request->merge(['codigo' => str_pad((string)$request->codigo, 6, '0', STR_PAD_LEFT)]);

        $validate = Validator::make($request->all(), [
            'codigo' => 'required|string|max:6|min:6',
        ]);

        if ($validate->fails()) 
        {
            return response()->json([
                'status' => 'error',
                'message' => $validate->errors()
            ], 422);
        }

        $codigo = Codigo::all()->first(function ($item) use ($request) {
            return Hash::check($request->codigo, $item->codigo);
        });

        if (!$codigo) 
        {
            return response()->json([
                'message' => 'Código de verificación incorrecto o cuenta ya activada'
            ], 400);
        }

        $user = User::where('id', $codigo->user_id)->first();

        if (!$user) 
        {
            return response()->json([
                'message' => 'Error al obtener el usuario'
            ], 400);
        }

        if ($user->role == 'desactivado') 
        {
            return response()->json([
                'message' => 'Cuenta desactivada'
            ], 400);
        }

        $user->role = 'jugador';
        $user->save();

        if ($user->role != 'jugador') 
        {
            return response()->json([
                'message' => 'Error al activar la cuenta'
            ], 400);
        }

        $codigo->delete();

        return response()->json([
            'message' => 'Cuenta activada',
            'user' => $user
        ], 201);
    }

    public function login(Request $request)
    {
        $validate = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        if ($validate->fails()) 
        {
            return response()->json([
                'status' => 'error',
                'message' => $validate->errors()
            ], 422);
        }

        $user = User::where('email', $request->email)->first();

        if (!$user) 
        {
            return response()->json([
                'message' => 'Credenciales incorrectas'
            ], 400);
        }

        if (!in_array($user->role, ['jugador', 'admin'])) 
        {
            return response()->json([
                'message' => 'El usuario no es un jugador o la cuenta fue desactivada'
            ], 400);
        }

        if (!Hash::check($request->password, $user->password)) 
        {
            return response()->json([
                'message' => 'Credenciales incorrectas'
            ], 400);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Inicio de sesión exitoso',
            'token' => $token
        ], 200);
    }
}
