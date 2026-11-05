<?php

namespace App\Casts;

use App\Services\DataProtection;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Keeps a column encrypted under the versioned data keys (DataProtection).
 *
 * @implements CastsAttributes<string|null, string|null>
 */
class ProtectedText implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : app(DataProtection::class)->decrypt((string) $value);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : app(DataProtection::class)->encrypt((string) $value);
    }
}
