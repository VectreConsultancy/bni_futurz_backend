<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Responsibility extends Model
{
    protected $table = 'tbl_responsibilities';
    protected $fillable = ['coordinator_id', 'role_id', 'name', 'level', 'period'];

    public function category()
    {
        return $this->belongsTo(CoordinatorCategory::class, 'coordinator_id');
    }
}
