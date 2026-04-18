<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Tenure extends Model
{
    protected $table = 'tbl_tenure';

    protected $fillable = [
        'year',
        'tenure',
        'created_by',
        'created_ip',
    ];
}
