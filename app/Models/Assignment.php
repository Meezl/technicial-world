<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Assignment extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'assignable_type',
        'assignable_id',
        'role'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function assignable()
    {
        return $this->morphTo();
    }
}
