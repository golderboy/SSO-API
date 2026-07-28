<?php

use App\Enums\AuthenticationTransactionStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        $statuses = implode(
            "', '",
            array_column(AuthenticationTransactionStatus::cases(), 'value'),
        );
        DB::statement(
            'ALTER TABLE authentication_transactions '
            .'DROP CONSTRAINT authentication_transactions_status_check, '
            .'ADD CONSTRAINT authentication_transactions_status_check '
            ."CHECK (status IN ('{$statuses}'))",
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        DB::statement(
            'ALTER TABLE authentication_transactions '
            .'DROP CONSTRAINT authentication_transactions_status_check, '
            .'ADD CONSTRAINT authentication_transactions_status_check '
            ."CHECK (status IN ('pending', 'provider_selected', "
            ."'organization_required', 'approved', 'issuing', 'consumed', "
            ."'denied'))",
        );
    }
};
