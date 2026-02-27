<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Strategy extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'name',
        'slug',
        'description',
        'price',
    ];

    /**
     * Relationship: A strategy can have many subscribed users.
     */
    public function users()
    {
        return $this->belongsToMany(User::class)
                    ->withPivot('receipt_path', 'status')
                    ->withTimestamps();
    }
}