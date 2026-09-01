<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class ManageAdmin extends Command
{
    protected $signature = 'user:admin {email} {--revoke : Remove admin rights instead of granting them}';

    protected $description = 'Grant or revoke admin rights for a user';

    public function handle(): int
    {
        $user = User::where('email', $this->argument('email'))->first();

        if (! $user) {
            $this->error("No user with email {$this->argument('email')}.");

            return self::FAILURE;
        }

        if ($this->option('revoke')) {
            $user->revokeAdmin();
            $this->info("{$user->email} is no longer an admin.");

            return self::SUCCESS;
        }

        $user->promoteToAdmin();
        $this->info("{$user->email} is now an admin. Note admins cannot donate.");

        return self::SUCCESS;
    }
}
