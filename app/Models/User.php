<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'phone',
        'address',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Get the technician profile for this user.
     */
    public function technician()
    {
        return $this->hasOne(Technician::class);
    }

    /**
     * Get all service requests for this user.
     */
    public function serviceRequests()
    {
        return $this->hasMany(ServiceRequest::class);
    }

    /**
     * Get all payments for this user.
     */
    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Get tasks assigned to this user.
     */
    public function assignedTasks()
    {
        return $this->hasMany(Task::class, 'assigned_to');
    }

    /**
     * Get projects created by this user.
     */
    public function createdProjects()
    {
        return $this->hasMany(Project::class, 'created_by');
    }

    /**
     * Get projects managed by this user.
     */
    public function managedProjects()
    {
        return $this->hasMany(Project::class, 'manager_id');
    }
}
