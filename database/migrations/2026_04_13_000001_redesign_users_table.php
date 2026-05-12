<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Change role enum to include project_manager
            $table->boolean('is_active')->default(true)->after('address');
            $table->timestamp('last_login_at')->nullable()->after('is_active');
            $table->string('profile_photo_path')->nullable()->after('last_login_at');
        });

        // Update role column to support PM
        if (DB::getDriverName() === 'sqlite') {
            $this->rebuildUsersTableForSqlite(['admin', 'client', 'technician', 'project_manager']);
        } else {
            DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('admin','client','technician','project_manager') DEFAULT 'client'");
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['is_active', 'last_login_at', 'profile_photo_path']);
        });
        if (DB::getDriverName() === 'sqlite') {
            $this->rebuildUsersTableForSqlite(['admin', 'client', 'technician']);
        } else {
            DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('admin','client','technician') DEFAULT 'client'");
        }
    }

    private function rebuildUsersTableForSqlite(array $roles): void
    {
        Schema::create('users_temp', function (Blueprint $table) use ($roles) {
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

        DB::table('users_temp')->insertUsing(
            [
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
            ],
            DB::table('users')->select(
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
            )
        );

        Schema::drop('users');
        Schema::rename('users_temp', 'users');
    }
};
