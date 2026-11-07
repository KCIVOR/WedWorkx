<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

use function Laravel\Prompts\password;

/** Creates an admin, or resets the password of an existing one. There is no public sign-up. */
class CreateAdmin extends Command
{
    protected $signature = 'admin:create {email : Sign-in email} {--name= : Display name}';

    protected $description = 'Create an admin account (or reset its password)';

    public function handle(): int
    {
        $email = strtolower(trim($this->argument('email')));
        if (Validator::make(['email' => $email], ['email' => 'required|email|max:160'])->fails()) {
            $this->error('That is not a valid email address.');

            return self::FAILURE;
        }

        $password = password('Password (at least 12 characters)', required: true);
        if (mb_strlen($password) < 12) {
            $this->error('The password must be at least 12 characters.');

            return self::FAILURE;
        }
        if ($password !== password('Type it again')) {
            $this->error('The two passwords do not match.');

            return self::FAILURE;
        }

        $user = User::firstOrNew(['email' => $email]);
        $created = ! $user->exists;
        $user->name = $this->option('name') ?: ($user->name ?: 'Admin');
        $user->password = $password; // hashed by the model cast
        $user->save();

        AuditLog::record($created ? 'admin_created' : 'admin_password_reset', ['email' => $email], $user->id);
        $this->info($created ? "Admin created. Sign in at /".config('admin.path').'/login' : 'Password updated.');

        return self::SUCCESS;
    }
}
