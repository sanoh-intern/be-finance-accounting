<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    use HasApiTokens,HasFactory, Notifiable;

    // Connection
    protected $connection = "mysql";

    // Table name
    protected $table = "user";

    protected $primaryKey = "user_id";

    // Column
    protected $fillable = [
        'bp_code',
        'name',
        'role',
        'status',
        'username',
        'password',
        'email'
    ];

    // Relationship
    public function InvHeader(): HasMany
    {
        return $this->hasMany(InvHeader::class, 'bp_code', 'bp_code');
    }
}
