<?php

namespace App\Models;

use App\Settings\ApplicationSettings;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use LogicException;

class ApplicationSetting extends Model
{
    protected $fillable = ['key', 'value'];

    protected function casts(): array
    {
        return ['value' => 'array'];
    }

    protected static function booted(): void
    {
        static::saving(function (self $setting): void {
            try {
                if (! is_array($setting->value)) {
                    throw new InvalidArgumentException('Application setting values must be structured arrays.');
                }

                ApplicationSettings::validateStored($setting->key, $setting->value);
            } catch (InvalidArgumentException $exception) {
                throw new LogicException('Application settings have a closed, validated non-secret key boundary.', previous: $exception);
            }
        });
    }
}
