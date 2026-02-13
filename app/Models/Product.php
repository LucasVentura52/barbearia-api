<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use HasFactory, BelongsToCompany;

    protected $fillable = [
        'company_id',
        'name',
        'description',
        'price',
        'stock',
        'active',
        'photo_url',
    ];

    protected $casts = [
        'active' => 'boolean',
        'price' => 'decimal:2',
        'stock' => 'integer',
    ];
}
