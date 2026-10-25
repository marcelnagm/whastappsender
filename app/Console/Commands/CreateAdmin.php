<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/** First administrator of a fresh install (the seeder is empty). Safe to re-run: an existing e-mail is promoted and gets the new password. */
class CreateAdmin extends Command
{
    protected $signature = 'user:admin {email : Login e-mail} {--name=Administrator} {--password= : Leave empty to generate one}';

    protected $description = 'Create (or promote) an administrator account';

    public function handle(): int
    {
        $email = strtolower(trim((string) $this->argument('email')));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('Invalid e-mail.');

            return self::FAILURE;
        }

        $generated = false;
        $password = (string) $this->option('password');
        if ($password === '') {
            $password = Str::random(20);
            $generated = true;
        } elseif (strlen($password) < 8) {
            $this->error('The password needs at least 8 characters.');

            return self::FAILURE;
        }

        $user = User::where('email', $email)->first() ?? new User();
        $created = !$user->exists;

        $user->name = $created ? (string) $this->option('name') : $user->name;
        $user->email = $email;
        $user->username = $user->username ?: $this->uniqueUsername($email);
        $user->password = $password; // hashed by the model mutator
        $user->forceFill(['role' => 'admin', 'active' => true])->save();

        $this->info(($created ? 'Administrator created: ' : 'User promoted to administrator: ') . $email);
        if ($generated) {
            $this->line("Generated password: {$password}");
        }

        return self::SUCCESS;
    }

    private function uniqueUsername(string $email): string
    {
        $base = Str::slug(Str::before($email, '@'), '') ?: 'admin';
        $name = $base;
        for ($i = 2; User::where('username', $name)->exists(); $i++) {
            $name = $base . $i;
        }

        return $name;
    }
}
