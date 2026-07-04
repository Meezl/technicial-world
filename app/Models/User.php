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

    const ROLE_ADMIN = 'admin';
    const ROLE_CLIENT = 'client';
    const ROLE_TECHNICIAN = 'technician';
    const ROLE_PROJECT_MANAGER = 'project_manager';
    const ROLE_STOREMAN = 'storeman';
    const ROLE_FOREMAN = 'foreman';
    const ROLE_OFFICE = 'office';
    const ROLE_PROCUREMENT = 'procurement';
    const ROLE_ACCOUNTS = 'accounts';

    // Single source of truth for valid role values. Mirrored by the
    // `users.role` ENUM (see sync_user_role_enum migration) and by
    // validation rules that accept a role on input.
    const ROLES = [
        self::ROLE_ADMIN,
        self::ROLE_CLIENT,
        self::ROLE_TECHNICIAN,
        self::ROLE_PROJECT_MANAGER,
        self::ROLE_STOREMAN,
        self::ROLE_FOREMAN,
        self::ROLE_OFFICE,
        self::ROLE_PROCUREMENT,
        self::ROLE_ACCOUNTS,
    ];

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'phone',
        'address',
        'is_active',
        'last_login_at',
        'profile_photo_path',
    ];

    /**
     * The attributes that should be hidden for serialization.
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'last_login_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    // ==================== ROLE CHECKS ====================

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function isClient(): bool
    {
        return $this->role === self::ROLE_CLIENT;
    }

    public function isTechnician(): bool
    {
        return $this->role === self::ROLE_TECHNICIAN;
    }

    public function isProjectManager(): bool
    {
        return $this->role === self::ROLE_PROJECT_MANAGER;
    }

    public function isStoreman(): bool
    {
        return $this->role === self::ROLE_STOREMAN;
    }

    public function hasRole(string ...$roles): bool
    {
        return in_array($this->role, $roles);
    }

    // ==================== RELATIONSHIPS ====================

    /**
     * Get the technician profile for this user.
     */
    public function technician()
    {
        return $this->hasOne(Technician::class);
    }

    /**
     * Get all service requests for this user (client).
     */
    public function serviceRequests()
    {
        return $this->hasMany(ServiceRequest::class);
    }

    /**
     * Service requests assigned to this PM.
     */
    public function assignedRfqs()
    {
        return $this->hasMany(ServiceRequest::class, 'assigned_pm_id');
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

    /**
     * Get quotations created by this user (PM).
     */
    public function quotations()
    {
        return $this->hasMany(Quotation::class, 'created_by');
    }

    /**
     * Conversations this user participates in.
     */
    public function conversations()
    {
        return $this->belongsToMany(Conversation::class, 'conversation_participants')
            ->withPivot('last_read_at')
            ->withTimestamps();
    }

    /**
     * Messages sent by this user.
     */
    public function sentMessages()
    {
        return $this->hasMany(Message::class, 'sender_id');
    }

    /**
     * Audit logs triggered by this user.
     */
    public function auditLogs()
    {
        return $this->hasMany(AuditLog::class);
    }

    /**
     * Get total unread message count.
     */
    public function getUnreadMessageCountAttribute(): int
    {
        $count = 0;
        foreach ($this->conversations as $conversation) {
            $count += $conversation->unreadCountFor($this);
        }
        return $count;
    }
}
