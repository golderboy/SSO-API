<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Rules\ThaiCitizenId;
use App\Services\PersonnelIdentityService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

#[Signature('sso:create-admin
    {--email= : Administrator email}
    {--name= : Administrator name}
    {--promote-existing : Promote the active user matching the entered CID}')]
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

        $cidValidator = Validator::make(['cid' => $cid], [
            'cid' => ['required', 'string', 'max:32', new ThaiCitizenId],
        ]);

        if ($cidValidator->fails()) {
            $this->printValidationErrors($cidValidator->errors()->all());

            return self::FAILURE;
        }

        $existingUser = User::withTrashed()
            ->where('cid_hash', $identity->hash($cid))
            ->first();

        if ($existingUser !== null && ! $this->option('promote-existing')) {
            $this->error(
                'A user with this citizen ID already exists. No changes were made. '
                .'Use --promote-existing only after verifying the existing account.',
            );

            return self::FAILURE;
        }

        if ($existingUser?->trashed()) {
            $this->error(
                'The matching user is deleted. Restore and review the account before promotion.',
            );

            return self::FAILURE;
        }

        $password = (string) $this->secret('Password (minimum 12 characters)');
        $confirmation = (string) $this->secret('Confirm password');
        $emailRule = Rule::unique('users', 'email');

        if ($existingUser !== null) {
            $emailRule->ignore($existingUser->getKey());
        }

        $validator = Validator::make([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'password_confirmation' => $confirmation,
        ], [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email:rfc', 'max:255', $emailRule],
            'password' => [
                'required',
                'confirmed',
                Password::min(12)->mixedCase()->numbers()->symbols(),
            ],
        ]);

        if ($validator->fails()) {
            $this->printValidationErrors($validator->errors()->all());

            return self::FAILURE;
        }

        try {
            DB::transaction(function () use (
                $cid,
                $email,
                $existingUser,
                $identity,
                $name,
                $password,
            ): void {
                $user = $existingUser ?? new User([
                    'public_id' => (string) Str::uuid(),
                ]);
                $user->fill([
                    'name' => $name,
                    'email' => $email,
                    'password' => $password,
                    'is_active' => true,
                    'is_super_admin' => true,
                ]);
                $identity->setCid($user, $cid);
                $user->save();

                if ($existingUser !== null) {
                    $user->tokens()->delete();
                }
            });
        } catch (UniqueConstraintViolationException) {
            $this->error(
                'An account with this email or citizen ID already exists. No changes were made.',
            );

            return self::FAILURE;
        }

        $action = $existingUser === null ? 'created' : 'promoted';
        $this->info(
            "Super administrator {$action}. "
            .'Use POST /api/v1/auth/login to obtain a token.',
        );

        return self::SUCCESS;
    }

    /**
     * @param  array<int, string>  $errors
     */
    private function printValidationErrors(array $errors): void
    {
        foreach ($errors as $error) {
            $this->error($error);
        }
    }
}
