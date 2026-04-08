<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BasicAssignment extends Model
{
    protected $table = 'tbl_basic_assignments';

    protected $fillable = [
        'user_id',
        'category_id',
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

    public function category()
    {
        return $this->belongsTo(CoordinatorCategory::class, 'category_id');
    }
}
