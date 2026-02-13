<?php

namespace App\Models;

use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    public const ROLE_CLIENT = 'client';
    public const ROLE_STAFF = 'staff';
    public const ROLE_ADMIN = 'admin';
    public const ROLE_SUPER_ADMIN = 'super_admin';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'phone',
        'email',
        'password',
        'role',
        'avatar_url',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
    ];

    public function staffProfile()
    {
        return $this->hasOne(StaffProfile::class);
    }

    public function staffProfileForCompany(int $companyId)
    {
        return $this->hasOne(StaffProfile::class)->where('company_id', $companyId);
    }

    public function services()
    {
        return $this->belongsToMany(Service::class, 'staff_services', 'staff_user_id', 'service_id')
            ->withPivot(['company_id'])
            ->withTimestamps();
    }

    public function servicesForCompany(int $companyId)
    {
        return $this->services()->wherePivot('company_id', $companyId);
    }

    public function companyMemberships()
    {
        return $this->hasMany(CompanyUser::class, 'user_id');
    }

    public function companies()
    {
        return $this->belongsToMany(Company::class, 'company_user')
            ->withPivot(['role', 'active'])
            ->withTimestamps();
    }

    public function scopeInCompany(Builder $query, int $companyId): Builder
    {
        return $query->whereHas('companyMemberships', function ($membershipQuery) use ($companyId) {
            $membershipQuery
                ->where('company_id', $companyId)
                ->where('active', true);
        });
    }

    public function scopeWithCompanyRoles(Builder $query, int $companyId, array $roles): Builder
    {
        return $query->whereHas('companyMemberships', function ($membershipQuery) use ($companyId, $roles) {
            $membershipQuery
                ->where('company_id', $companyId)
                ->where('active', true)
                ->whereIn('role', $roles);
        });
    }

    public function companyRole(?int $companyId = null): ?string
    {
        if (!$companyId && app()->bound(TenantContext::class)) {
            $companyId = app(TenantContext::class)->id();
        }

        if (!$companyId) {
            return $this->role;
        }

        return CompanyUser::query()
            ->where('company_id', $companyId)
            ->where('user_id', $this->id)
            ->where('active', true)
            ->value('role');
    }

    public function isStaff(?int $companyId = null): bool
    {
        $role = $this->companyRole($companyId);

        return in_array($role, [self::ROLE_STAFF, self::ROLE_ADMIN, self::ROLE_SUPER_ADMIN], true);
    }

    public function isAdmin(?int $companyId = null): bool
    {
        if ($this->role === self::ROLE_SUPER_ADMIN) {
            return true;
        }

        return $this->companyRole($companyId) === self::ROLE_ADMIN;
    }
}
