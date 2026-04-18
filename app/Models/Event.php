<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Event extends Model
{
    protected $table = 'tbl_events';

    protected $fillable = ['name', 'date', 'description', 'venue', 'tenure_id', 'created_by', 'updated_by', 'created_ip', 'updated_ip'];

    public function assignments()
    {
        return $this->hasMany(EventAssignment::class);
    }

    public function tenure()
    {
        return $this->belongsTo(Tenure::class);
    }
}
