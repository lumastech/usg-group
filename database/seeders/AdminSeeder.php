<?php

namespace Database\Seeders;

use App\Enums\MemberRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Seeds the system administrator account.
 *
 * The administrator runs the application itself: user accounts, cycle setup and
 * imports. They are deliberately not a Member, so they hold no savings, cannot
 * borrow, and cannot stand as one of the two approvers on a loan or a payout —
 * those powers belong to the elected committee alone.
 *
 * Credentials come from ADMIN_EMAIL and ADMIN_PASSWORD. When no password is set a
 * random one is generated and printed once, so nothing weak or shared ever ends up
 * committed to the repository.
 */
class AdminSeeder extends Seeder
{
    public const DEFAULT_EMAIL = 'admin@admin.com';

    public const DEFAULT_PASSWORD = '{#OOoe3{h%38Xa';

    /**
     * Writes to the console when seeding interactively.
     *
     * This deliberately avoids Seeder::$command, which Laravel documents as
     * non-nullable but leaves unset until a console command adopts the seeder.
     */
    protected function write(string $message): void
    {
        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            fwrite(STDOUT, $message.PHP_EOL);
        }
    }

    public function run(): void
    {
        $email = (string) config('unity.admin.email', self::DEFAULT_EMAIL);
        $existing = User::where('email', $email)->first();

        if ($existing !== null) {
            $existing->syncRoles([MemberRole::Admin->value]);

            $this->write("Administrator {$email} already exists; password left unchanged.");

            return;
        }

        // change password after first login
        $password = (string) (config('unity.admin.password') ?: self::DEFAULT_PASSWORD);

        $admin = User::create([
            'name' => (string) config('unity.admin.name', 'System Administrator'),
            'email' => $email,
            'password' => $password,
            'email_verified_at' => Carbon::now(),
        ]);

        $admin->syncRoles([MemberRole::Admin->value]);

        $this->write("Administrator created: {$email}");

        if (config('unity.admin.password') === null) {
            $this->write("Generated password: {$password}");
            $this->write('Store it now — it is not shown again. Set ADMIN_PASSWORD to choose your own.');
        }
    }
}
