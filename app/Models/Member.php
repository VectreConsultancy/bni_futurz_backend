<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Notifications\Notifiable;

class Member extends Authenticatable
{
    use HasApiTokens, Notifiable;

    protected $table = 'tbl_members';
    protected $primaryKey = 'member_id';

    protected $fillable = [
        'category_id',
        'team_id',
        'member_name',
        'mobile_no',
        'otp',
        'otp_expires_at',
    ];

    protected $casts = [
        'category_id' => 'json',
        'otp_expires_at' => 'datetime',
    ];
}
