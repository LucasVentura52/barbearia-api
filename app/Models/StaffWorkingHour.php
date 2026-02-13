<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StaffWorkingHour extends Model
{
    use HasFactory, BelongsToCompany;

    protected $fillable = [
        'company_id',
        'staff_user_id',
        'weekday',
        'start_time',
        'end_time',
    ];

    protected $casts = [
        'weekday' => 'integer',
    ];

    public function staff()
    {
        return $this->belongsTo(User::class, 'staff_user_id');
    }
}
