<?php

namespace Modules\UserManagement\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class UserType extends Model
{
    use SoftDeletes;

    protected $table = 'user_types';

    protected $fillable = [
        'name',
    ];

    protected $dates = [
        'deleted_at',
    ];
}
