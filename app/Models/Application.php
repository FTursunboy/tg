<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Application extends Model
{
    protected $fillable = [
        'bot_user_id',
        'telegram_user_id',
        'chat_id',
        'country',
        'car_class',
        'registration_number',
        'full_name',
        'photos',
        'status',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'photos' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }

    public function botUser(): BelongsTo
    {
        return $this->belongsTo(BotUser::class);
    }
}
