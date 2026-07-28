<?php

use App\Enums\SystemRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $legacyAdminCount = DB::table('users')
            ->where('is_super_admin', true)
            ->count();

        if ($legacyAdminCount > 1) {
            throw new RuntimeException(
                'More than one legacy super administrator exists. '
                .'Resolve the accounts before applying the system-role migration.',
            );
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->string('system_role', 20)
                ->default(SystemRole::User->value)
                ->index()
                ->after('is_active');
            $table->unsignedTinyInteger('admin_slot')
                ->nullable()
                ->unique()
                ->after('system_role');
        });

        if ($legacyAdminCount === 1) {
            DB::table('users')
                ->where('is_super_admin', true)
                ->update([
                    'system_role' => SystemRole::Admin->value,
                    'admin_slot' => 1,
                ]);
        }

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement(
                'ALTER TABLE users ADD CONSTRAINT users_system_role_check CHECK ('
                ."(system_role = 'admin' AND admin_slot = 1) OR "
                ."(system_role IN ('super_admin', 'user') AND admin_slot IS NULL)"
                .')',
            );
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex('users_is_super_admin_index');
            $table->dropColumn('is_super_admin');
        });
    }

    public function down(): void
    {
        $driver = DB::getDriverName();

        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_super_admin')->default(false)->index();
        });

        DB::table('users')
            ->whereIn('system_role', [
                SystemRole::Admin->value,
                SystemRole::SuperAdmin->value,
            ])
            ->update(['is_super_admin' => true]);

        if ($driver === 'mariadb') {
            DB::statement(
                'ALTER TABLE users DROP CONSTRAINT users_system_role_check',
            );
        } elseif ($driver === 'mysql') {
            DB::statement(
                'ALTER TABLE users DROP CHECK users_system_role_check',
            );
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique('users_admin_slot_unique');
            $table->dropIndex('users_system_role_index');
            $table->dropColumn(['system_role', 'admin_slot']);
        });
    }
};
