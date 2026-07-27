<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('application_sso_configs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('application_id')
                ->unique()
                ->constrained('applications')
                ->cascadeOnDelete();
            $table->foreignUuid('oauth_client_id')
                ->unique()
                ->constrained('oauth_clients')
                ->restrictOnDelete();
            $table->json('allowed_providers');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('application_sso_configs');
    }
};
