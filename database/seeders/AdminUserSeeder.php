<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create admin user if it doesn't exist
        $admin = User::firstOrCreate(
            ['email' => 'admin@technicianworld.com'],
            [
                'name' => 'System Administrator',
                'email' => 'admin@technicianworld.com',
                'password' => Hash::make('admin123'),
                'email_verified_at' => now(),
                'role' => 'admin',
            ]
        );

        // Create a test client user as well
        $client = User::firstOrCreate(
            ['email' => 'client@technicianworld.com'],
            [
                'name' => 'Test Client',
                'email' => 'client@technicianworld.com',
                'password' => Hash::make('client123'),
                'email_verified_at' => now(),
                'role' => 'client',
            ]
        );

        // Create a test technician user
        $technician = User::firstOrCreate(
            ['email' => 'technician@technicianworld.com'],
            [
                'name' => 'Test Technician',
                'email' => 'technician@technicianworld.com',
                'password' => Hash::make('tech123'),
                'email_verified_at' => now(),
                'role' => 'technician',
            ]
        );

        // Create Foreman user
        $foreman = User::firstOrCreate(
            ['email' => 'foreman@technicianworld.com'],
            [
                'name' => 'Site Foreman',
                'email' => 'foreman@technicianworld.com',
                'password' => Hash::make('foreman123'),
                'email_verified_at' => now(),
                'role' => 'foreman',
            ]
        );

        // Create Office user
        $office = User::firstOrCreate(
            ['email' => 'office@technicianworld.com'],
            [
                'name' => 'Office Admin',
                'email' => 'office@technicianworld.com',
                'password' => Hash::make('office123'),
                'email_verified_at' => now(),
                'role' => 'office',
            ]
        );

        // Create Procurement user
        $procurement = User::firstOrCreate(
            ['email' => 'procurement@technicianworld.com'],
            [
                'name' => 'Procurement Officer',
                'email' => 'procurement@technicianworld.com',
                'password' => Hash::make('pro123'),
                'email_verified_at' => now(),
                'role' => 'procurement',
            ]
        );

        // Create Accounts user
        $accounts = User::firstOrCreate(
            ['email' => 'accounts@technicianworld.com'],
            [
                'name' => 'Accounts Manager',
                'email' => 'accounts@technicianworld.com',
                'password' => Hash::make('acc123'),
                'email_verified_at' => now(),
                'role' => 'accounts',
            ]
        );

        $this->command->info('Users created successfully:');
        $this->command->info('Admin: admin@technicianworld.com / admin123');
        $this->command->info('Client: client@technicianworld.com / client123');
        $this->command->info('Technician: technician@technicianworld.com / tech123');
        $this->command->info('Foreman: foreman@technicianworld.com / foreman123');
        $this->command->info('Office: office@technicianworld.com / office123');
        $this->command->info('Procurement: procurement@technicianworld.com / pro123');
        $this->command->info('Accounts: accounts@technicianworld.com / acc123');
    }
}
