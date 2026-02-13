<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AddMultiTenantFoundation extends Migration
{
    public function up()
    {
        $isPretending = DB::connection()->pretending();

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('company_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role')->default('client');
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'user_id']);
            $table->index(['company_id', 'role', 'active']);
            $table->index(['user_id', 'active']);
        });

        $tables = [
            'services',
            'products',
            'appointments',
            'staff_profiles',
            'staff_services',
            'staff_working_hours',
            'staff_time_offs',
            'media',
        ];

        foreach ($tables as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            });
        }

        DB::statement('ALTER TABLE staff_profiles DROP CONSTRAINT IF EXISTS staff_profiles_user_id_unique');
        DB::statement('ALTER TABLE staff_services DROP CONSTRAINT IF EXISTS staff_services_staff_user_id_service_id_unique');

        Schema::table('staff_profiles', function (Blueprint $table) {
            $table->unique(['company_id', 'user_id']);
        });

        Schema::table('staff_services', function (Blueprint $table) {
            $table->unique(['company_id', 'staff_user_id', 'service_id']);
        });

        $defaultName = env('DEFAULT_COMPANY_NAME', env('APP_NAME', 'Barbearia'));
        $defaultSlug = env('DEFAULT_COMPANY_SLUG', 'default');
        $defaultSlug = Str::slug($defaultSlug) ?: 'default';

        $companyId = DB::table('companies')->where('slug', $defaultSlug)->value('id');
        if (!$companyId) {
            DB::table('companies')->insert([
                'name' => $defaultName,
                'slug' => $defaultSlug,
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $companyId = DB::table('companies')->where('slug', $defaultSlug)->value('id');
        }

        if (!$companyId) {
            if ($isPretending) {
                $companyId = 1;
            } else {
                throw new \RuntimeException('Unable to resolve default company id during migration.');
            }
        }

        foreach ($tables as $tableName) {
            DB::table($tableName)
                ->whereNull('company_id')
                ->update(['company_id' => $companyId]);
        }

        $users = DB::table('users')->select('id', 'role')->get();
        $now = now();
        $membershipRows = [];
        foreach ($users as $user) {
            $membershipRows[] = [
                'company_id' => $companyId,
                'user_id' => $user->id,
                'role' => $user->role ?: 'client',
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if (!empty($membershipRows)) {
            DB::table('company_user')->upsert(
                $membershipRows,
                ['company_id', 'user_id'],
                ['role', 'active', 'updated_at']
            );
        }

        foreach ($tables as $tableName) {
            DB::statement(sprintf(
                'ALTER TABLE %s ALTER COLUMN company_id SET NOT NULL',
                $tableName
            ));
        }

        Schema::table('services', function (Blueprint $table) {
            $table->index(['company_id', 'active']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->index(['company_id', 'active']);
        });

        Schema::table('appointments', function (Blueprint $table) {
            $table->index(['company_id', 'start_at']);
        });

        Schema::table('staff_profiles', function (Blueprint $table) {
            $table->index(['company_id', 'active']);
        });

        Schema::table('staff_working_hours', function (Blueprint $table) {
            $table->index(['company_id', 'staff_user_id', 'weekday']);
        });

        Schema::table('staff_time_offs', function (Blueprint $table) {
            $table->index(['company_id', 'staff_user_id', 'start_at']);
        });

        Schema::table('media', function (Blueprint $table) {
            $table->index(['company_id', 'owner_type', 'owner_id']);
        });
    }

    public function down()
    {
        Schema::table('media', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'owner_type', 'owner_id']);
        });

        Schema::table('staff_time_offs', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'staff_user_id', 'start_at']);
        });

        Schema::table('staff_working_hours', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'staff_user_id', 'weekday']);
        });

        Schema::table('staff_profiles', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'active']);
        });

        DB::statement('ALTER TABLE staff_profiles DROP CONSTRAINT IF EXISTS staff_profiles_company_id_user_id_unique');
        DB::statement('ALTER TABLE staff_profiles ADD CONSTRAINT staff_profiles_user_id_unique UNIQUE (user_id)');

        Schema::table('appointments', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'start_at']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'active']);
        });

        Schema::table('services', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'active']);
        });

        DB::statement('ALTER TABLE staff_services DROP CONSTRAINT IF EXISTS staff_services_company_id_staff_user_id_service_id_unique');
        DB::statement('ALTER TABLE staff_services ADD CONSTRAINT staff_services_staff_user_id_service_id_unique UNIQUE (staff_user_id, service_id)');

        $tables = [
            'services',
            'products',
            'appointments',
            'staff_profiles',
            'staff_services',
            'staff_working_hours',
            'staff_time_offs',
            'media',
        ];

        foreach ($tables as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropConstrainedForeignId('company_id');
            });
        }

        Schema::dropIfExists('company_user');
        Schema::dropIfExists('companies');
    }
}
