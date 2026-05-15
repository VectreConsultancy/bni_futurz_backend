<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RoleAssignment extends Model
{
    protected $table = 'tbl_role_assignments';

    protected $fillable = [
        'user_id',
        'role_id',
        'tenure_id',
        'period',
        'week_number',
        'month_number',
        'year',
        'responsibility_checklist',
        'created_ip',
        'updated_ip',
    ];

    protected $casts = [
        'responsibility_checklist' => 'json',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function tenure()
    {
        return $this->belongsTo(Tenure::class);
    }
}
