<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['key', 'value'])]
class TrackerSetting extends Model
{
    public $incrementing = false;

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public static function value(string $key, mixed $default = null): mixed
    {
        return static::query()->whereKey($key)->value('value') ?? $default;
    }
}
