<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CoordinatorCategory extends Model
{
    protected $table = 'master_coordinator_categories';
    protected $fillable = ['role_id', 'category_name'];

    public function role()
    {
        return $this->belongsTo(Role::class, 'role_id', 'role_id');
    }
}
