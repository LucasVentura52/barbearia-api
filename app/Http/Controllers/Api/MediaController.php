<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Media;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class MediaController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate([
            'owner_type' => ['nullable', 'string', 'in:service,product,staff,user'],
            'owner_id' => ['nullable', 'integer'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $query = Media::query();

        if (!empty($data['owner_type'])) {
            $query->where('owner_type', $data['owner_type']);
        }

        if (!empty($data['owner_id'])) {
            $query->where('owner_id', $data['owner_id']);
        }

        $limit = $data['limit'] ?? 50;

        return response()->json(
            $query->orderByDesc('id')->limit($limit)->get()
        );
    }

    public function destroy(Media $media)
    {
        $this->deleteStoredFile($media->url);

        $media->delete();

        return response()->json(['message' => 'Media deleted']);
    }

    private function deleteStoredFile(string $url): void
    {
        $publicPrefix = '/storage/';
        $path = parse_url($url, PHP_URL_PATH) ?? $url;

        if (str_starts_with($path, $publicPrefix)) {
            $relativePath = substr($path, strlen($publicPrefix));
            Storage::disk('public')->delete($relativePath);
        }
    }
}
