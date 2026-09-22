<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformSetting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'value', 'is_secret', 'updated_by'];

    protected $hidden = ['value'];

    protected function casts(): array
    {
        return [
            'is_secret' => 'boolean',
        ];
    }
}
