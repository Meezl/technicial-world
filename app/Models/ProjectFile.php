<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\Storage;

class ProjectFile extends Model
{
    use HasFactory;

    protected $fillable = [
        'fileable_type',
        'fileable_id',
        'file_name',
        'file_path',
        'file_size',
        'mime_type',
        'uploaded_by',
    ];

    protected $casts = [
        'file_size' => 'integer',
    ];

    // Polymorphic relationship
    public function fileable()
    {
        return $this->morphTo();
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    // Business logic methods
    public function getDownloadUrl()
    {
        return route('admin.projects.files.download', $this->id);
    }

    public function getFileSizeFormattedAttribute()
    {
        $bytes = $this->file_size;
        $units = ['B', 'KB', 'MB', 'GB'];
        $factor = floor((strlen($bytes) - 1) / 3);

        return sprintf("%.2f", $bytes / pow(1024, $factor)) . ' ' . $units[$factor];
    }

    public function delete()
    {
        // Delete the file from storage
        if (Storage::disk('public')->exists($this->file_path)) {
            Storage::disk('public')->delete($this->file_path);
        }

        // Delete the database record
        return parent::delete();
    }

    // Query scopes
    public function scopeForProject($query, $projectId)
    {
        return $query->where('fileable_type', Project::class)
                     ->where('fileable_id', $projectId);
    }

    public function scopeForTask($query, $taskId)
    {
        return $query->where('fileable_type', Task::class)
                     ->where('fileable_id', $taskId);
    }
}
