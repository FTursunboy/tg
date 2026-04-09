<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BotUser extends Model
{
    protected $fillable = [
        'telegram_user_id',
        'chat_id',
        'username',
        'first_name',
        'last_name',
        'state',
        'draft',
    ];

    protected function casts(): array
    {
        return [
            'draft' => 'array',
        ];
    }
}
