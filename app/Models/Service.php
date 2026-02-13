<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Service extends Model
{
    use HasFactory, BelongsToCompany;

    protected $fillable = [
        'company_id',
        'name',
        'description',
        'duration_minutes',
        'price',
        'active',
        'photo_url',
    ];

    protected $casts = [
        'active' => 'boolean',
        'price' => 'decimal:2',
        'duration_minutes' => 'integer',
    ];

    public function staff()
    {
        return $this->belongsToMany(User::class, 'staff_services', 'service_id', 'staff_user_id')
            ->withPivot(['company_id']);
    }
}
