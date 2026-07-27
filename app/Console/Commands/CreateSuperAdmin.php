<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Rules\ThaiCitizenId;
use App\Services\PersonnelIdentityService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

#[Signature('sso:create-admin {--email=} {--name=}')]
#[Description('Create the initial SSO super administrator interactively')]
class CreateSuperAdmin extends Command
{
    public function handle(PersonnelIdentityService $identity): int
    {
        $name = (string) ($this->option('name') ?: $this->ask('Administrator name'));
        $email = strtolower(trim(
            (string) ($this->option('email') ?: $this->ask('Administrator email')),
        ));
        $cid = (string) $this->secret('Thai citizen ID (input is hidden)');
        $password = (string) $this->secret('Password (minimum 12 characters)');
        $confirmation = (string) $this->secret('Confirm password');

        $validator = Validator::make([
            'name' => $name,
            'email' => $email,
            'cid' => $cid,
            'password' => $password,
            'password_confirmation' => $confirmation,
        ], [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email:rfc', 'max:255', 'unique:users,email'],
            'cid' => ['required', 'string', 'max:32', new ThaiCitizenId],
            'password' => [
                'required',
                'confirmed',
                Password::min(12)->mixedCase()->numbers()->symbols(),
            ],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user = new User([
            'public_id' => (string) Str::uuid(),
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'is_active' => true,
            'is_super_admin' => true,
        ]);
        $identity->setCid($user, $cid);
        $user->save();

        $this->info('Super administrator created. Use POST /api/v1/auth/login to obtain a token.');

        return self::SUCCESS;
    }
}
