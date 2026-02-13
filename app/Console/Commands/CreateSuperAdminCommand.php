<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class CreateSuperAdminCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'app:super-admin:create
        {phone : E.164 phone (example: +5511999999999)}
        {name : Full name}
        {password : Plain password}
        {--email= : Optional e-mail}';

    /**
     * @var string
     */
    protected $description = 'Create or promote a user to super_admin role';

    public function handle()
    {
        $phone = (string) $this->argument('phone');
        $name = (string) $this->argument('name');
        $password = (string) $this->argument('password');
        $email = $this->option('email');

        $user = User::query()->where('phone', $phone)->first();

        if (!$user) {
            $user = User::query()->create([
                'name' => $name,
                'phone' => $phone,
                'email' => $email ?: null,
                'password' => Hash::make($password),
                'role' => User::ROLE_SUPER_ADMIN,
            ]);

            $this->info('Super admin created: #' . $user->id . ' ' . $user->name);

            return self::SUCCESS;
        }

        $user->name = $name ?: $user->name;
        if ($email !== null) {
            $user->email = $email ?: null;
        }
        $user->password = Hash::make($password);
        $user->role = User::ROLE_SUPER_ADMIN;
        $user->save();

        $this->info('User promoted to super_admin: #' . $user->id . ' ' . $user->name);

        return self::SUCCESS;
    }
}
