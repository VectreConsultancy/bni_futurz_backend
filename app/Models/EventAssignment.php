<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EventAssignment extends Model
{
    protected $table = 'tbl_event_assignments';

    protected $fillable = [
        'event_id', 'user_id', 'category_id', 'team_id', 'responsibility_checklist', 
        'created_by', 'updated_by', 'created_ip', 'updated_ip'
    ];

    protected $casts = [
        'responsibility_checklist' => 'json'
    ];

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function category()
    {
        return $this->belongsTo(CoordinatorCategory::class, 'category_id');
    }
}
