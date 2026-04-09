# Telegram Bot (Laravel + Webhook)

Простой Laravel-проект для Telegram-бота с анкетой и модерацией.

## Что умеет бот

- Команда `/start` запускает анкету.
- Вопросы пользователю:
  - выбрать страну: Таджикистан / Узбекистан / Кыргызстан / Казахстан
  - выбрать класс: Дрифт / Тюнинг / Ретро / Автозвук
  - регистрационный номер машины
  - ФИО
  - 4 фотографии машины
- После 4 фото заявка сохраняется в БД со статусом `pending`.
- Модераторы получают заявку и фото, могут нажать:
  - `Одобрить`
  - `Отклонить`
- После решения пользователь получает уведомление.
- Команда модератора `/list` показывает список заявок `pending`.

## Настройка

1. Заполните `.env`:
   - `DB_*`
   - `TELEGRAM_BOT_TOKEN`
   - `TELEGRAM_ADMIN_ID` (опционально, один модератор)
   - `TELEGRAM_ADMIN_IDS` (через запятую, несколько модераторов)
   - `TELEGRAM_WEBHOOK_SECRET` (необязательно, но желательно)

2. Выполните миграции:

```bash
php artisan migrate
```

3. Установите webhook в Telegram:

```bash
curl -X POST "https://api.telegram.org/bot<YOUR_BOT_TOKEN>/setWebhook" \
  -d "url=https://your-domain.com/telegram/webhook/<YOUR_SECRET>"
```

Если секрет не используете, URL может быть таким:

```text
https://your-domain.com/telegram/webhook
```

4. Запустите Laravel (локально):

```bash
php artisan serve --host=0.0.0.0 --port=8000
```

## Важные маршруты

- `POST /telegram/webhook/{secret?}`
- `GET /` — проверка, что приложение отвечает
