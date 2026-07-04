<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Realigns the users.role ENUM with App\Models\User::ROLES.
    // The 2026_04_13 redesign migration rewrote the enum and dropped the
    // requisition roles (foreman, office, procurement, accounts) added by
    // 2025_12_12_072000. `storeman` was also referenced by code/routes but
    // never made it into the column at all — admin user creation hit
    // SQLSTATE 1265 "Data truncated for column 'role'".
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $this->rebuildUsersTableForSqlite(User::ROLES);
            return;
        }

        $list = "'" . implode("','", User::ROLES) . "'";
        DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM($list) DEFAULT 'client'");
    }

    public function down(): void
    {
        $previous = ['admin', 'client', 'technician', 'project_manager'];

        if (DB::getDriverName() === 'sqlite') {
            $this->rebuildUsersTableForSqlite($previous);
            return;
        }

        $list = "'" . implode("','", $previous) . "'";
        DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM($list) DEFAULT 'client'");
    }

    private function rebuildUsersTableForSqlite(array $roles): void
    {
        Schema::create('users_role_sync_tmp', function (Blueprint $table) use ($roles) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->enum('role', $roles)->default('client');
            $table->string('phone')->nullable();
            $table->text('address')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_login_at')->nullable();
            $table->string('profile_photo_path')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        $columns = [
            'id',
            'name',
            'email',
            'email_verified_at',
            'password',
            'role',
            'phone',
            'address',
            'is_active',
            'last_login_at',
            'profile_photo_path',
            'remember_token',
            'created_at',
            'updated_at',
        ];

        DB::table('users_role_sync_tmp')->insertUsing(
            $columns,
            DB::table('users')->select($columns)
        );

        Schema::drop('users');
        Schema::rename('users_role_sync_tmp', 'users');
    }
};
