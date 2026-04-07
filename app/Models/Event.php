<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Event extends Model
{
    protected $table = 'tbl_events';

    protected $fillable = ['name', 'date', 'description', 'created_by', 'updated_by', 'created_ip', 'updated_ip'];

    public function assignments()
    {
        return $this->hasMany(EventAssignment::class);
    }
}
