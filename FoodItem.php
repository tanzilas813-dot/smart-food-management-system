<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class FoodItem extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'category_id',
        'name',
        'brand',
        'quantity',
        'unit',
        'purchase_date',
        'expiry_date',
        'storage_location',
        'status',
        'notes',
        'image',
    ];

    protected function casts(): array
    {
        return [
            'expiry_date' => 'date',
            'purchase_date' => 'date',
            'quantity' => 'decimal:2',
        ];
    }

    // ─── Relationships ────────────────────────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function foodLogs(): HasMany
    {
        return $this->hasMany(FoodLog::class);
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }

    // ─── Query Scopes ─────────────────────────────────────────────────────────

    /**
     * Items that are still active and not yet expired.
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Items expiring within the next 7 days.
     */
    public function scopeExpiringSoon($query)
    {
        return $query->where('status', 'active')
            ->whereBetween('expiry_date', [now(), now()->addDays(7)]);
    }

    /**
     * Items whose expiry date has passed.
     */
    public function scopeExpired($query)
    {
        return $query->where('status', 'expired')
            ->orWhere(function ($q) {
                $q->where('status', 'active')
                    ->where('expiry_date', '<', now());
            });
    }

    /**
     * Items that were wasted.
     */
    public function scopeWasted($query)
    {
        return $query->where('status', 'wasted');
    }
}
