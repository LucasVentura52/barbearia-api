<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Media extends Model
{
    use HasFactory, BelongsToCompany;

    protected $fillable = [
        'company_id',
        'owner_type',
        'owner_id',
        'url',
        'type',
    ];

    public function owner()
    {
        return $this->morphTo();
    }
}
