<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $username = env('ADMIN_USERNAME', 'admin');
        $email    = env('ADMIN_EMAIL', 'admin@example.com');
        $password = env('ADMIN_PASSWORD', 'password');
        $name     = env('ADMIN_NAME', 'Amministratore');

        $user = User::updateOrCreate(
            ['username' => $username],
            [
                'name'                    => $name,
                'email'                   => $email,
                'password'                => Hash::make($password),
                'attivo'                  => true,
                'amministratore'          => true,
                'gestore_segnalazioni'    => false,
                'supervisore_segnalazioni'=> false,
                'email_verified_at'       => now(),
            ]
        );

        $user->syncRoles(['admin']);

        $this->command->info("Utente admin creato: {$username} / (password da .env ADMIN_PASSWORD)");
    }
}
