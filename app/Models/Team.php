<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Team extends Model
{
    protected $table = 'master_teams';
    protected $fillable = ['category_id', 'team_name'];

    public function category()
    {
        return $this->belongsTo(CoordinatorCategory::class, 'category_id');
    }
}
