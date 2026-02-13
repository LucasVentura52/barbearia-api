<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Media;
use App\Support\ImageUploader;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class ProfileController extends Controller
{
    public function uploadPhoto(Request $request)
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'image', 'max:10240'],
        ]);

        $stored = ImageUploader::store($data['file']);
        $url = $stored['url'];

        $user = $request->user();
        $user->update(['avatar_url' => $url]);

        $media = Media::create([
            'owner_type' => 'user',
            'owner_id' => $user->id,
            'url' => $url,
            'type' => 'image',
        ]);

        return response()->json([
            'url' => $url,
            'media' => $media,
            'user' => $user,
        ]);
    }

    public function updatePassword(Request $request)
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:6', 'confirmed'],
        ]);

        $user = $request->user();

        if (!$user || !Hash::check($data['current_password'], $user->password)) {
            return response()->json(['message' => 'Senha atual inválida.'], 422);
        }

        $user->password = Hash::make($data['password']);
        $user->save();

        return response()->json(['message' => 'Senha atualizada com sucesso.']);
    }
}
