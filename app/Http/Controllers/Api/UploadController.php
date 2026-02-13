<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CompanyUser;
use App\Models\Media;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Support\ImageUploader;
use Illuminate\Http\Request;

class UploadController extends Controller
{
    private const OWNER_TYPES = [
        'service' => Service::class,
        'product' => Product::class,
        'staff' => User::class,
        'user' => User::class,
    ];

    public function store(Request $request)
    {
        $companyId = (int) $this->currentCompanyId();
        $currentUser = $request->user();

        $data = $request->validate([
            'file' => ['required', 'file', 'image', 'max:10240'],
            'owner_type' => ['nullable', 'string', 'in:service,product,staff,user'],
            'owner_id' => ['nullable', 'integer', 'required_with:owner_type'],
        ]);

        if (!empty($data['owner_type'])) {
            $exists = false;

            if (in_array($data['owner_type'], ['service', 'product'], true)) {
                $modelClass = self::OWNER_TYPES[$data['owner_type']];
                $exists = $modelClass::query()->whereKey($data['owner_id'])->exists();
            }

            if ($data['owner_type'] === 'staff') {
                $exists = CompanyUser::query()
                    ->where('company_id', $companyId)
                    ->where('user_id', $data['owner_id'])
                    ->where('active', true)
                    ->whereIn('role', [User::ROLE_STAFF, User::ROLE_ADMIN])
                    ->exists();
            }

            if ($data['owner_type'] === 'user') {
                $exists = CompanyUser::query()
                    ->where('company_id', $companyId)
                    ->where('user_id', $data['owner_id'])
                    ->where('active', true)
                    ->exists();

                $canManageOtherUsers = $currentUser && $currentUser->isAdmin($companyId);
                $isSelf = $currentUser && (int) $currentUser->id === (int) $data['owner_id'];
                if ($exists && !$isSelf && !$canManageOtherUsers) {
                    return response()->json(['message' => 'Forbidden'], 403);
                }
            }

            if (!$exists) {
                return response()->json(['message' => 'Owner not found'], 422);
            }
        }

        $file = $request->file('file');
        $stored = ImageUploader::store($file);
        $path = $stored['path'];
        $url = $stored['url'];

        $media = null;
        if (!empty($data['owner_type'])) {
            $media = Media::create([
                'owner_type' => $data['owner_type'],
                'owner_id' => $data['owner_id'],
                'url' => $url,
                'type' => 'image',
            ]);
        }

        return response()->json([
            'url' => $url,
            'path' => $path,
            'media' => $media,
        ], 201);
    }
}
