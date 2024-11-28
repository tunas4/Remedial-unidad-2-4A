<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Codigo extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'codigo'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
