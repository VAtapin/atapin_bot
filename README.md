# Семейный Telegram-бот

Telegram-бот и Mini App для большой семьи: генеалогическое древо с фотографиями, карточки людей, родственные связи, семейные события и уведомления о днях рождения. Все данные и доступы управляются через Filament.

## Что уже работает

- люди: ФИО, даты жизни, фото, города, биография, профессия и публикация;
- связи «родитель — ребёнок», усыновление и опека;
- пары, браки, разводы и памятные даты;
- интерактивное древо в Telegram Mini App;
- поиск и фильтры по полу, городу и статусу;
- ближайшие дни рождения в Mini App и по команде `/birthdays`;
- ежедневные уведомления в подтверждённые семейные группы;
- заявки пользователей и групп с ручным подтверждением;
- журнал входящих Telegram-обновлений;
- Filament-админка на `/admin`.

## Требования

- PHP 8.3+ с `curl`, `fileinfo`, `intl`, `mbstring`, `openssl`, `pdo_sqlite` или PDO для вашей БД;
- Composer;
- Node.js 20+;
- публичный HTTPS-домен для Telegram webhook и Mini App.

## Локальный запуск

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan storage:link
npm install
npm run build
php artisan make:filament-user
php artisan serve
```

Откройте `http://127.0.0.1:8000/admin`. Для просмотра Mini App вне Telegram укажите в локальном `.env`:

```dotenv
TELEGRAM_DEV_USER_ID=1
```

Локальный пользователь будет создан при первом обращении. Разрешите ему доступ в разделе «Доступ пользователей».

## Подключение Telegram

1. Перевыпустите токен через BotFather, если он когда-либо публиковался.
2. Заполните `.env`:

```dotenv
APP_URL=https://family.example.com
TELEGRAM_BOT_TOKEN=новый_токен
TELEGRAM_BOT_USERNAME=atapin_bot
TELEGRAM_WEBHOOK_SECRET=длинная_случайная_строка
TELEGRAM_MINI_APP_URL=https://family.example.com/family
TELEGRAM_ADMIN_IDS=123456789
```

3. В BotFather настройте домен Mini App командой `/setdomain`.
4. Зарегистрируйте webhook и команды:

```bash
php artisan telegram:set-webhook
```

5. Добавьте в cron ежеминутный запуск планировщика:

```cron
* * * * * cd /var/www/atapin_bot && php artisan schedule:run >> /dev/null 2>&1
```

Новые чаты и пользователи не получают семейные данные автоматически. Подтвердите группу в «Группы», а человека — в «Доступ пользователей».

## Основные разделы админки

- «Люди» — карточки и фотографии;
- «Родители и дети» — направленные связи поколений;
- «Пары и браки» — связи партнёров;
- «События» — годовщины, встречи и памятные даты;
- «Группы» — разрешённые семейные чаты и время уведомлений;
- «Доступ пользователей» — заявки, блокировка и связь с карточкой человека;
- «Администраторы» — пользователи Filament;
- «Журнал Telegram» — диагностика webhook.

## Проверка

```bash
php artisan test
npm run build
```

Настоящие токены, `.env`, база SQLite и загруженные фотографии исключены из Git.
