<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StaffService extends Model
{
    use HasFactory, BelongsToCompany;

    protected $fillable = [
        'company_id',
        'staff_user_id',
        'service_id',
    ];
}
