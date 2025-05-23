<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServerRule extends Model
{
    protected $table = 'v2_rule';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];
}
