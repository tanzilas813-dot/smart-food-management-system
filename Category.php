<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'icon',
        'color',
        'description',
    ];

    /**
     * A category has many food items.
     */
    public function foodItems(): HasMany
    {
        return $this->hasMany(FoodItem::class);
    }
}
