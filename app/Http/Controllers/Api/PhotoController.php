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

class PhotoController extends Controller
{
    public function service(Service $service, Request $request)
    {
        $url = $this->storeImage($request);

        $service->update(['photo_url' => $url]);

        $media = Media::create([
            'owner_type' => 'service',
            'owner_id' => $service->id,
            'url' => $url,
            'type' => 'image',
        ]);

        return response()->json(['url' => $url, 'media' => $media]);
    }

    public function product(Product $product, Request $request)
    {
        $url = $this->storeImage($request);

        $product->update(['photo_url' => $url]);

        $media = Media::create([
            'owner_type' => 'product',
            'owner_id' => $product->id,
            'url' => $url,
            'type' => 'image',
        ]);

        return response()->json(['url' => $url, 'media' => $media]);
    }

    public function staff(User $user, Request $request)
    {
        $companyId = (int) $this->currentCompanyId();
        $isStaffInCompany = CompanyUser::query()
            ->where('company_id', $companyId)
            ->where('user_id', $user->id)
            ->where('active', true)
            ->whereIn('role', [User::ROLE_STAFF, User::ROLE_ADMIN])
            ->exists();

        if (!$isStaffInCompany) {
            return response()->json(['message' => 'Staff not found'], 404);
        }

        $url = $this->storeImage($request);

        $user->update(['avatar_url' => $url]);

        $media = Media::create([
            'owner_type' => 'staff',
            'owner_id' => $user->id,
            'url' => $url,
            'type' => 'image',
        ]);

        return response()->json(['url' => $url, 'media' => $media]);
    }

    private function storeImage(Request $request): string
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'image', 'max:10240'],
        ]);

        $stored = ImageUploader::store($data['file']);

        return $stored['url'];
    }
}
