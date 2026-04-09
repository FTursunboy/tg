<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\BotUser;
use App\Services\TelegramService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TelegramWebhookController extends Controller
{
    private const COUNTRIES = [
        'Таджикистан',
        'Узбекистан',
        'Кыргызстан',
        'Казахстан',
    ];

    private const CAR_CLASSES = [
        'Дрифт',
        'Тюнинг',
        'Ретро',
        'Автозвук',
    ];

    public function __construct(private readonly TelegramService $telegram)
    {
    }

    public function handle(Request $request, ?string $secret = null): JsonResponse
    {
        $configuredSecret = (string) config('services.telegram.webhook_secret');

        if ($configuredSecret !== '' && ! hash_equals($configuredSecret, (string) $secret)) {
            return response()->json(['ok' => false], 403);
        }

        $update = $request->all();

        if (isset($update['callback_query']) && is_array($update['callback_query'])) {
            $this->handleCallbackQuery($update['callback_query']);
        }

        if (isset($update['message']) && is_array($update['message'])) {
            $this->handleMessage($update['message']);
        }

        return response()->json(['ok' => true]);
    }

    private function handleMessage(array $message): void
    {
        $chatId = (int) data_get($message, 'chat.id', 0);
        $userId = (int) data_get($message, 'from.id', 0);

        if ($chatId === 0 || $userId === 0) {
            return;
        }

        $text = trim((string) ($message['text'] ?? ''));

        if ($text === '/start') {
            $botUser = $this->getOrCreateBotUser($message);
            $this->startFlow($botUser);

            return;
        }

        if ($text === '/list' && $this->isAdmin($userId)) {
            $this->sendPendingListToModerator($chatId);

            return;
        }

        $botUser = $this->getOrCreateBotUser($message);

        match ($botUser->state) {
            'awaiting_country' => $this->handleCountryStep($botUser, $text),
            'awaiting_class' => $this->handleClassStep($botUser, $text),
            'awaiting_registration' => $this->handleRegistrationStep($botUser, $text),
            'awaiting_full_name' => $this->handleFullNameStep($botUser, $text),
            'awaiting_photos' => $this->handlePhotoStep($botUser, $message),
            default => $this->telegram->sendMessage($chatId, 'Нажмите /start, чтобы начать заполнение заявки.'),
        };
    }

    private function handleCallbackQuery(array $callbackQuery): void
    {
        $callbackId = (string) data_get($callbackQuery, 'id', '');
        $moderatorId = (int) data_get($callbackQuery, 'from.id', 0);
        $data = (string) data_get($callbackQuery, 'data', '');

        if ($callbackId === '') {
            return;
        }

        if (! $this->isAdmin($moderatorId)) {
            $this->telegram->answerCallbackQuery($callbackId, 'Только модератор может это делать.');

            return;
        }

        if (! preg_match('/^(approve|reject|photos):(\d+)$/', $data, $matches)) {
            $this->telegram->answerCallbackQuery($callbackId, 'Неизвестное действие.');

            return;
        }

        $action = $matches[1];
        $application = Application::find((int) $matches[2]);

        if (! $application) {
            $this->telegram->answerCallbackQuery($callbackId, 'Заявка не найдена.');

            return;
        }

        $moderatorChatId = (int) data_get($callbackQuery, 'message.chat.id', 0);
        if ($action === 'photos') {
            if ($moderatorChatId === 0) {
                $this->telegram->answerCallbackQuery($callbackId, 'Не удалось определить чат.');

                return;
            }

            $this->sendApplicationPhotosToModerator($moderatorChatId, $application);
            $this->telegram->answerCallbackQuery($callbackId, 'Альбом отправлен.');

            return;
        }

        if ($application->status !== 'pending') {
            $moderatorMessageId = (int) data_get($callbackQuery, 'message.message_id', 0);
            if ($moderatorChatId !== 0 && $moderatorMessageId !== 0) {
                $this->telegram->editMessageReplyMarkup($moderatorChatId, $moderatorMessageId, [
                    'inline_keyboard' => [],
                ]);
            }

            $this->telegram->answerCallbackQuery($callbackId, 'Заявка уже обработана.');

            return;
        }

        $newStatus = $action === 'approve' ? 'approved' : 'rejected';

        $application->update([
            'status' => $newStatus,
            'reviewed_at' => now(),
        ]);

        $this->telegram->answerCallbackQuery(
            $callbackId,
            $newStatus === 'approved' ? 'Заявка одобрена.' : 'Заявка отклонена.'
        );

        $moderatorMessageId = (int) data_get($callbackQuery, 'message.message_id', 0);
        if ($moderatorChatId !== 0 && $moderatorMessageId !== 0) {
            $this->telegram->editMessageReplyMarkup($moderatorChatId, $moderatorMessageId, [
                'inline_keyboard' => [],
            ]);
        }

        $this->telegram->sendMessage(
            $application->chat_id,
            $newStatus === 'approved'
                ? $this->approvedMessage($application)
                : "Ваша заявка #{$application->id} отклонена."
        );
    }

    private function getOrCreateBotUser(array $message): BotUser
    {
        $userId = (int) data_get($message, 'from.id');

        $botUser = BotUser::firstOrCreate(
            ['telegram_user_id' => $userId],
            [
                'chat_id' => (int) data_get($message, 'chat.id'),
                'username' => data_get($message, 'from.username'),
                'first_name' => data_get($message, 'from.first_name'),
                'last_name' => data_get($message, 'from.last_name'),
                'state' => 'idle',
                'draft' => [],
            ]
        );

        $botUser->update([
            'chat_id' => (int) data_get($message, 'chat.id'),
            'username' => data_get($message, 'from.username'),
            'first_name' => data_get($message, 'from.first_name'),
            'last_name' => data_get($message, 'from.last_name'),
        ]);

        return $botUser;
    }

    private function startFlow(BotUser $botUser): void
    {
        $botUser->update([
            'state' => 'awaiting_country',
            'draft' => ['photos' => []],
        ]);

        $this->telegram->sendMessage(
            $botUser->chat_id,
            'Выберите страну:',
            [
                'reply_markup' => $this->makeReplyKeyboard(self::COUNTRIES, 2),
            ]
        );
    }

    private function handleCountryStep(BotUser $botUser, string $text): void
    {
        if (! in_array($text, self::COUNTRIES, true)) {
            $this->telegram->sendMessage(
                $botUser->chat_id,
                'Пожалуйста, выберите страну кнопкой ниже.',
                ['reply_markup' => $this->makeReplyKeyboard(self::COUNTRIES, 2)]
            );

            return;
        }

        $draft = $botUser->draft ?? [];
        $draft['country'] = $text;

        $botUser->update([
            'state' => 'awaiting_class',
            'draft' => $draft,
        ]);

        $this->telegram->sendMessage(
            $botUser->chat_id,
            'Выберите класс:',
            [
                'reply_markup' => $this->makeReplyKeyboard(self::CAR_CLASSES, 2),
            ]
        );
    }

    private function handleClassStep(BotUser $botUser, string $text): void
    {
        if (! in_array($text, self::CAR_CLASSES, true)) {
            $this->telegram->sendMessage(
                $botUser->chat_id,
                'Пожалуйста, выберите класс кнопкой ниже.',
                ['reply_markup' => $this->makeReplyKeyboard(self::CAR_CLASSES, 2)]
            );

            return;
        }

        $draft = $botUser->draft ?? [];
        $draft['car_class'] = $text;

        $botUser->update([
            'state' => 'awaiting_registration',
            'draft' => $draft,
        ]);

        $this->telegram->sendMessage(
            $botUser->chat_id,
            'Введите регистрационный номер машины:',
            [
                'reply_markup' => ['remove_keyboard' => true],
            ]
        );
    }

    private function handleRegistrationStep(BotUser $botUser, string $text): void
    {
        if ($text === '') {
            $this->telegram->sendMessage(
                $botUser->chat_id,
                'Введите регистрационный номер машины.'
            );

            return;
        }

        $draft = $botUser->draft ?? [];
        $draft['registration_number'] = $text;

        $botUser->update([
            'state' => 'awaiting_full_name',
            'draft' => $draft,
        ]);

        $this->telegram->sendMessage($botUser->chat_id, 'Введите ФИО:');
    }

    private function handleFullNameStep(BotUser $botUser, string $text): void
    {
        if ($text === '' || mb_strlen($text) < 5 || mb_strlen($text) > 120) {
            $this->telegram->sendMessage($botUser->chat_id, 'Введите корректное ФИО (от 5 до 120 символов).');

            return;
        }

        $draft = $botUser->draft ?? [];
        $draft['full_name'] = $text;
        $draft['photos'] = $draft['photos'] ?? [];

        $botUser->update([
            'state' => 'awaiting_photos',
            'draft' => $draft,
        ]);

        $this->telegram->sendMessage(
            $botUser->chat_id,
            'Отправьте 4 фотографии машины с разных сторон (по одной или альбомом).'
        );
    }

    private function handlePhotoStep(BotUser $botUser, array $message): void
    {
        $photoSizes = data_get($message, 'photo', []);

        if (! is_array($photoSizes) || $photoSizes === []) {
            $this->telegram->sendMessage($botUser->chat_id, 'Нужно отправить фотографию. Сейчас ожидаются 4 фото машины.');

            return;
        }

        $lastPhoto = end($photoSizes);
        $fileId = data_get($lastPhoto, 'file_id');

        if (! is_string($fileId) || $fileId === '') {
            $this->telegram->sendMessage($botUser->chat_id, 'Не удалось получить фото. Попробуйте отправить снова.');

            return;
        }

        $draft = $botUser->draft ?? [];
        $photos = $draft['photos'] ?? [];

        if (count($photos) >= 4) {
            $this->telegram->sendMessage($botUser->chat_id, 'Уже получено 4 фото. Ожидайте ответ модератора.');

            return;
        }

        $photos[] = $fileId;
        $draft['photos'] = $photos;

        if (count($photos) < 4) {
            $botUser->update(['draft' => $draft]);

            $current = count($photos);
            $this->telegram->sendMessage($botUser->chat_id, "Фото {$current}/4 получено. Отправьте следующее фото.");

            return;
        }

        if (
            empty($draft['country'])
            || empty($draft['car_class'])
            || empty($draft['registration_number'])
            || empty($draft['full_name'])
        ) {
            $this->startFlow($botUser);
            $this->telegram->sendMessage($botUser->chat_id, 'Произошла ошибка в данных. Заполните заявку заново.');

            return;
        }

        $application = Application::create([
            'bot_user_id' => $botUser->id,
            'telegram_user_id' => $botUser->telegram_user_id,
            'chat_id' => $botUser->chat_id,
            'country' => $draft['country'],
            'car_class' => $draft['car_class'],
            'registration_number' => $draft['registration_number'],
            'full_name' => $draft['full_name'],
            'photos' => $photos,
            'status' => 'pending',
        ]);

        $botUser->update([
            'state' => 'idle',
            'draft' => [],
        ]);

        $this->telegram->sendMessage(
            $botUser->chat_id,
            'Спасибо! Мы с вами свяжемся. Заявка отправлена модератору.',
            [
                'reply_markup' => ['remove_keyboard' => true],
            ]
        );

        $this->sendApplicationToModerator($application);
    }

    private function sendApplicationToModerator(Application $application): void
    {
        $moderatorIds = $this->moderatorIds();
        if ($moderatorIds === []) {
            return;
        }

        foreach ($moderatorIds as $moderatorChatId) {
            $this->telegram->sendMessage(
                $moderatorChatId,
                $this->formatApplicationText($application),
                [
                    'reply_markup' => $this->moderatorButtons($application->id),
                    'parse_mode' => 'HTML',
                ]
            );

            $this->sendApplicationPhotosToModerator($moderatorChatId, $application);
        }
    }

    private function sendPendingListToModerator(int $chatId): void
    {
        $applications = Application::query()
            ->where('status', 'pending')
            ->latest()
            ->limit(30)
            ->get();

        if ($applications->isEmpty()) {
            $this->telegram->sendMessage($chatId, 'Новых заявок нет.');

            return;
        }

        foreach ($applications as $application) {
            $this->telegram->sendMessage(
                $chatId,
                $this->formatApplicationText($application),
                [
                    'reply_markup' => $this->moderatorButtons($application->id),
                    'parse_mode' => 'HTML',
                ]
            );
        }
    }

    private function formatApplicationText(Application $application): string
    {
        $application->loadMissing('botUser');
        $username = trim((string) ($application->botUser?->username ?? ''));
        $userLabel = $username !== ''
            ? '@'.ltrim($username, '@')
            : '<a href="tg://user?id='.$application->telegram_user_id.'">Профиль</a>';

        return implode("\n", [
            'Заявка #'.$application->id,
            'Статус: '.$this->escapeHtml($this->statusLabel($application->status)),
            'Страна: '.$this->escapeHtml($application->country),
            'Класс: '.$this->escapeHtml($application->car_class),
            'Номер: '.$this->escapeHtml($application->registration_number),
            'ФИО: '.$this->escapeHtml($application->full_name),
            'Пользователь: '.$userLabel,
            'Создана: '.$this->escapeHtml((string) $application->created_at),
        ]);
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'pending' => 'ожидается',
            'approved' => 'одобрена',
            'rejected' => 'отклонена',
            default => $status,
        };
    }

    private function escapeHtml(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function moderatorButtons(int $applicationId): array
    {
        return [
            'inline_keyboard' => [
                [
                    ['text' => '📷 Фото', 'callback_data' => "photos:{$applicationId}"],
                ],
                [
                    ['text' => '✅ Одобрить', 'callback_data' => "approve:{$applicationId}"],
                    ['text' => '❌ Отклонить', 'callback_data' => "reject:{$applicationId}"],
                ],
            ],
        ];
    }

    private function sendApplicationPhotosToModerator(int $chatId, Application $application): void
    {
        $photos = $application->photos ?? [];
        if (! is_array($photos) || $photos === []) {
            $this->telegram->sendMessage($chatId, 'У этой заявки нет фотографий.');

            return;
        }

        $this->telegram->sendMediaGroup(
            $chatId,
            $photos,
            'Фото машины по заявке #'.$application->id
        );
    }

    private function makeReplyKeyboard(array $values, int $rowSize): array
    {
        $keyboard = [];

        foreach (array_chunk($values, $rowSize) as $rowValues) {
            $row = [];
            foreach ($rowValues as $value) {
                $row[] = ['text' => $value];
            }
            $keyboard[] = $row;
        }

        return [
            'keyboard' => $keyboard,
            'resize_keyboard' => true,
            'one_time_keyboard' => true,
        ];
    }

    private function isAdmin(int $telegramUserId): bool
    {
        return $telegramUserId !== 0 && in_array($telegramUserId, $this->moderatorIds(), true);
    }

    private function moderatorIds(): array
    {
        $ids = [];

        $singleId = (int) config('services.telegram.admin_id');
        if ($singleId > 0) {
            $ids[$singleId] = true;
        }

        $csv = trim((string) config('services.telegram.admin_ids', ''));
        if ($csv !== '') {
            foreach (preg_split('/\s*,\s*/', $csv, -1, PREG_SPLIT_NO_EMPTY) as $value) {
                $id = (int) $value;
                if ($id > 0) {
                    $ids[$id] = true;
                }
            }
        }

        return array_map('intval', array_keys($ids));
    }

    private function approvedMessage(Application $application): string
    {
        $eventRegistrationNumber = $application->id;

        return implode("\n", [
            "🎉 Поздравляем! Вы прошли регистрацию, ваш регистрационный номер — {$eventRegistrationNumber}.",
            '',
            '📅 Мероприятие пройдет 18-19 апреля на територии нового авто рынка.',
            '🚗 Заезд участников начнется 17 ноября с 12:00 до 22:00.',
            '',
            'Обязательно подпишитесь на на наш канал Khujand auto fest 2026',
            '',
            'Там мы публикуем:',
            '1️⃣ Время заезда',
            '2️⃣ Правила участия на фестивале',
            '3️⃣ Расстановку',
            '📰 А также все другие новости!',
            '',
            '–––',
            '',
            '📢 Уважаемые участники!',
            '',
            'Если ваш автомобиль оклеен логотипами или брендами каких-либо компаний,',
            'необходимо сообщить об этом администрации по номеру 📞 +992 927746424 (Telegram) для согласования.',
            '',
            'В противном случае администрация вправе не допустить автомобиль на площадку без предварительного согласования.',
            '',
            '🙏 Благодарим за понимание и сотрудничество.',
        ]);
    }
}
