<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Comment extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'commentable_type',
        'commentable_id',
        'parent_comment_id',
        'content',
        'mentions',
        'attachments',
    ];

    protected $casts = [
        'mentions' => 'array',
        'attachments' => 'array',
    ];

    // Relationships
    public function commentable()
    {
        return $this->morphTo();
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function parentComment()
    {
        return $this->belongsTo(Comment::class, 'parent_comment_id');
    }

    public function replies()
    {
        return $this->hasMany(Comment::class, 'parent_comment_id');
    }

    public function reactions()
    {
        return $this->morphMany(Reaction::class, 'reactable');
    }

    // Business logic methods
    public function extractMentions($content)
    {
        // Extract @mentions from content (e.g., @username or @John Doe)
        preg_match_all('/@(\w+(?:\s+\w+)*)/', $content, $matches);

        if (empty($matches[1])) {
            return [];
        }

        // Find user IDs based on names
        $userIds = [];
        foreach ($matches[1] as $name) {
            $user = User::where('name', 'like', "%{$name}%")->first();
            if ($user) {
                $userIds[] = $user->id;
            }
        }

        return array_unique($userIds);
    }

    // Automatically extract mentions before saving
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($comment) {
            if ($comment->content && !$comment->mentions) {
                $comment->mentions = $comment->extractMentions($comment->content);
            }
        });
    }

    // Query scopes
    public function scopeTopLevel($query)
    {
        return $query->whereNull('parent_comment_id');
    }
}
