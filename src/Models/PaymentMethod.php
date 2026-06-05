<?php

namespace Lyre\Billing\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\Schema;
use Lyre\Model;

class PaymentMethod extends Model
{
    use HasFactory;

    protected $casts = [
        'details' => 'array',
        'is_default' => 'boolean',
        'metadata' => 'array',
    ];

    public static function get($name)
    {
        // Remove all special characters and convert to lowercase for both the input and the DB column
        // Example: 'M-Pesa' => 'mpesa'
        // Use LOWER(REGEXP_REPLACE(...)) for database-safe normalization if supported (e.g., PostgreSQL), else fallback to PHP normalization.
        $normalizedInput = strtolower(preg_replace('/[^a-z0-9]+/i', '', $name));
        $table = (new static())->getTable();
        $column = Schema::hasColumn($table, 'name')
            ? 'name'
            : (Schema::hasColumn($table, 'provider') ? 'provider' : 'name');

        // For Laravel/Eloquent, we use a whereRaw to apply the same transform in SQL
        return self::whereRaw(
            "LOWER(REGEXP_REPLACE({$column}, '[^a-zA-Z0-9]+', '', 'g')) = ?",
            [$normalizedInput]
        )->first();
    }

    public function getNameAttribute($value): ?string
    {
        return $value ?: $this->getAttribute('provider');
    }

    public function setNameAttribute($value): void
    {
        if (Schema::hasColumn($this->getTable(), 'name')) {
            $this->attributes['name'] = $value;
        }

        if (Schema::hasColumn($this->getTable(), 'provider') && ! isset($this->attributes['provider'])) {
            $this->attributes['provider'] = $value;
        }
    }

    public function user()
    {
        return $this->belongsTo(get_user_model());
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }
}
