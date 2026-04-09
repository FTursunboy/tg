<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramService
{
    public function sendMessage(int|string $chatId, string $text, array $extra = []): void
    {
        $this->call('sendMessage', array_merge([
            'chat_id' => $chatId,
            'text' => $text,
        ], $extra));
    }

    public function sendPhoto(int|string $chatId, string $photoFileId, array $extra = []): void
    {
        $this->call('sendPhoto', array_merge([
            'chat_id' => $chatId,
            'photo' => $photoFileId,
        ], $extra));
    }

    public function sendMediaGroup(int|string $chatId, array $photoFileIds, ?string $caption = null): void
    {
        $media = [];

        foreach (array_values($photoFileIds) as $index => $photoFileId) {
            $item = [
                'type' => 'photo',
                'media' => (string) $photoFileId,
            ];

            if ($index === 0 && $caption !== null && $caption !== '') {
                $item['caption'] = $caption;
            }

            $media[] = $item;
        }

        if ($media === []) {
            return;
        }

        $this->call('sendMediaGroup', [
            'chat_id' => $chatId,
            'media' => $media,
        ]);
    }

    public function answerCallbackQuery(string $callbackQueryId, ?string $text = null): void
    {
        $payload = [
            'callback_query_id' => $callbackQueryId,
        ];

        if ($text !== null && $text !== '') {
            $payload['text'] = $text;
        }

        $this->call('answerCallbackQuery', $payload);
    }

    public function editMessageReplyMarkup(int|string $chatId, int $messageId, array $replyMarkup = []): void
    {
        $replyMarkupPayload = $replyMarkup === [] ? ['inline_keyboard' => []] : $replyMarkup;

        $this->call('editMessageReplyMarkup', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'reply_markup' => $replyMarkupPayload,
        ]);
    }

    public function call(string $method, array $payload): void
    {
        $token = (string) config('services.telegram.bot_token');

        if ($token === '') {
            Log::warning('Telegram bot token is not configured.');

            return;
        }

        $response = Http::timeout(20)
            ->post("https://api.telegram.org/bot{$token}/{$method}", $payload);

        if (! $response->ok()) {
            Log::error('Telegram API call failed.', [
                'method' => $method,
                'payload' => $payload,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
        }
    }
}
