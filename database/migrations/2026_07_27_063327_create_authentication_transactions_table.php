<?php

use App\Enums\AuthenticationTransactionStatus;
use App\Enums\IdentityProvider;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('authentication_transactions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('application_sso_config_id')
                ->constrained('application_sso_configs')
                ->cascadeOnDelete();
            $table->string('browser_session_hash', 64);
            $table->text('downstream_request');
            $table->string('upstream_state_hash', 64)->nullable()->unique();
            $table->string('selected_provider', 20)->nullable();
            $table->string('status', 30)
                ->default(AuthenticationTransactionStatus::Pending->value)
                ->index();
            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->foreignId('access_grant_id')
                ->nullable()
                ->constrained('access_grants')
                ->nullOnDelete();
            $table->foreignId('organization_id')
                ->nullable()
                ->constrained('organizations')
                ->nullOnDelete();
            $table->timestamp('expires_at')->index();
            $table->timestamp('authenticated_at')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->index([
                'application_sso_config_id',
                'browser_session_hash',
                'status',
            ], 'authentication_transactions_browser_lookup');
        });

        if (DB::getDriverName() !== 'sqlite') {
            $statuses = implode(
                "', '",
                array_column(AuthenticationTransactionStatus::cases(), 'value'),
            );
            $providers = implode(
                "', '",
                array_column(IdentityProvider::cases(), 'value'),
            );
            DB::statement(
                'ALTER TABLE authentication_transactions '
                .'ADD CONSTRAINT authentication_transactions_status_check '
                ."CHECK (status IN ('{$statuses}')), "
                .'ADD CONSTRAINT authentication_transactions_provider_check '
                ."CHECK (selected_provider IS NULL OR selected_provider IN ('{$providers}'))",
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('authentication_transactions');
    }
};
