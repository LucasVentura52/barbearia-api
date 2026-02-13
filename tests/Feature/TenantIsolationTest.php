<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\StaffProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_endpoint_returns_only_selected_company_staff()
    {
        $companyA = Company::query()->create([
            'name' => 'Alpha',
            'slug' => 'alpha',
            'active' => true,
        ]);

        $companyB = Company::query()->create([
            'name' => 'Beta',
            'slug' => 'beta',
            'active' => true,
        ]);

        $staffA = User::factory()->create([
            'name' => 'Staff Alpha',
            'phone' => '551100000001',
            'role' => User::ROLE_STAFF,
        ]);

        $staffB = User::factory()->create([
            'name' => 'Staff Beta',
            'phone' => '551100000002',
            'role' => User::ROLE_STAFF,
        ]);

        CompanyUser::query()->create([
            'company_id' => $companyA->id,
            'user_id' => $staffA->id,
            'role' => User::ROLE_STAFF,
            'active' => true,
        ]);

        CompanyUser::query()->create([
            'company_id' => $companyB->id,
            'user_id' => $staffB->id,
            'role' => User::ROLE_STAFF,
            'active' => true,
        ]);

        StaffProfile::withoutGlobalScopes()->create([
            'company_id' => $companyA->id,
            'user_id' => $staffA->id,
            'active' => true,
        ]);

        StaffProfile::withoutGlobalScopes()->create([
            'company_id' => $companyB->id,
            'user_id' => $staffB->id,
            'active' => true,
        ]);

        $response = $this
            ->withHeader('X-Company-Slug', 'alpha')
            ->getJson('/api/staff');

        $response
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonFragment(['id' => $staffA->id])
            ->assertJsonMissing(['id' => $staffB->id]);
    }

    public function test_login_fails_for_company_without_membership()
    {
        $companyA = Company::query()->create([
            'name' => 'Alpha',
            'slug' => 'alpha',
            'active' => true,
        ]);

        $companyB = Company::query()->create([
            'name' => 'Beta',
            'slug' => 'beta',
            'active' => true,
        ]);

        $user = User::factory()->create([
            'phone' => '551100000010',
            'password' => Hash::make('secret123'),
            'role' => User::ROLE_CLIENT,
        ]);

        CompanyUser::query()->create([
            'company_id' => $companyA->id,
            'user_id' => $user->id,
            'role' => User::ROLE_CLIENT,
            'active' => true,
        ]);

        $forbiddenLogin = $this
            ->withHeader('X-Company-Slug', $companyB->slug)
            ->postJson('/api/auth/login', [
                'phone' => $user->phone,
                'password' => 'secret123',
            ]);

        $forbiddenLogin->assertStatus(422);

        $allowedLogin = $this
            ->withHeader('X-Company-Slug', $companyA->slug)
            ->postJson('/api/auth/login', [
                'phone' => $user->phone,
                'password' => 'secret123',
            ]);

        $allowedLogin
            ->assertOk()
            ->assertJsonStructure(['token', 'user']);
    }

    public function test_admin_cannot_manage_staff_from_other_company()
    {
        $companyA = Company::query()->create([
            'name' => 'Alpha',
            'slug' => 'alpha',
            'active' => true,
        ]);

        $companyB = Company::query()->create([
            'name' => 'Beta',
            'slug' => 'beta',
            'active' => true,
        ]);

        $admin = User::factory()->create([
            'phone' => '551100000020',
            'role' => User::ROLE_ADMIN,
        ]);

        $staffFromOtherCompany = User::factory()->create([
            'phone' => '551100000021',
            'role' => User::ROLE_STAFF,
        ]);

        CompanyUser::query()->create([
            'company_id' => $companyA->id,
            'user_id' => $admin->id,
            'role' => User::ROLE_ADMIN,
            'active' => true,
        ]);

        CompanyUser::query()->create([
            'company_id' => $companyB->id,
            'user_id' => $staffFromOtherCompany->id,
            'role' => User::ROLE_STAFF,
            'active' => true,
        ]);

        StaffProfile::withoutGlobalScopes()->create([
            'company_id' => $companyB->id,
            'user_id' => $staffFromOtherCompany->id,
            'active' => true,
        ]);

        $token = $admin->createToken('test')->plainTextToken;

        $response = $this
            ->withHeader('X-Company-Slug', $companyA->slug)
            ->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/api/admin/staff/' . $staffFromOtherCompany->id, [
                'name' => 'Should Not Update',
            ]);

        $response->assertStatus(404);
    }

    public function test_authenticated_request_is_blocked_when_membership_is_from_another_company()
    {
        $companyA = Company::query()->create([
            'name' => 'Alpha',
            'slug' => 'alpha',
            'active' => true,
        ]);

        $companyB = Company::query()->create([
            'name' => 'Beta',
            'slug' => 'beta',
            'active' => true,
        ]);

        $user = User::factory()->create([
            'phone' => '551100000030',
            'role' => User::ROLE_CLIENT,
        ]);

        CompanyUser::query()->create([
            'company_id' => $companyA->id,
            'user_id' => $user->id,
            'role' => User::ROLE_CLIENT,
            'active' => true,
        ]);

        $token = $user->createToken('test')->plainTextToken;

        $response = $this
            ->withHeader('X-Company-Slug', $companyB->slug)
            ->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/me');

        $response->assertStatus(403);
    }
}
