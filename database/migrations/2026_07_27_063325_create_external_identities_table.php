<?php

use App\Enums\IdentityProvider;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('external_identities', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();
            $table->string('provider', 20);
            $table->string('subject_hash', 64);
            $table->string('identity_match_hash', 64);
            $table->timestamp('linked_at');
            $table->timestamp('last_authenticated_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['provider', 'subject_hash'],
                'external_identities_provider_subject_unique',
            );
            $table->unique(
                ['provider', 'identity_match_hash'],
                'external_identities_provider_match_unique',
            );
        });

        if (DB::getDriverName() !== 'sqlite') {
            $providers = implode(
                "', '",
                array_column(IdentityProvider::cases(), 'value'),
            );
            DB::statement(
                'ALTER TABLE external_identities '
                .'ADD CONSTRAINT external_identities_provider_check '
                ."CHECK (provider IN ('{$providers}'))",
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('external_identities');
    }
};
