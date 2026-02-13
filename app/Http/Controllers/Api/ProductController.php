<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $query = Product::query();

        $includeInactive = $request->boolean('include_inactive');
        $user = $request->user();

        if (!$includeInactive || !$user || !$user->isStaff()) {
            $query->where('active', true);
        }

        return response()->json($query->orderBy('name')->get());
    }

    public function show(Product $product)
    {
        if (!$product->active) {
            return response()->json(['message' => 'Product not found'], 404);
        }

        return response()->json($product);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string'],
            'price' => ['required', 'numeric', 'min:0'],
            'stock' => ['nullable', 'integer', 'min:0'],
            'active' => ['sometimes', 'boolean'],
            'photo_url' => ['nullable', 'string', 'max:255'],
        ]);

        $product = Product::create($data);

        return response()->json($product, 201);
    }

    public function update(Request $request, Product $product)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'description' => ['nullable', 'string'],
            'price' => ['sometimes', 'numeric', 'min:0'],
            'stock' => ['nullable', 'integer', 'min:0'],
            'active' => ['sometimes', 'boolean'],
            'photo_url' => ['nullable', 'string', 'max:255'],
        ]);

        $product->fill($data);
        $product->save();

        return response()->json($product);
    }

    public function destroy(Product $product)
    {
        $product->delete();

        return response()->json(['message' => 'Product deleted']);
    }
}
