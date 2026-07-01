# Семейный Telegram-бот

Telegram-бот и Telegram Mini App для большой семьи.

Проект позволяет вести единый семейный архив: генеалогическое древо, карточки родственников, фотографии, памятные даты, дни рождения и семейные события. Управление всеми данными осуществляется через административную панель Filament.

---

# Возможности

На данный момент реализовано:

- карточки людей (ФИО, полные даты жизни, фотографии, места, профессии, биография);
- неограниченное число фотографий и фотоальбомы;
- публикация и скрытие карточек;
- родители и дети;
- усыновление и опека;
- пары, браки и разводы;
- интерактивное семейное древо с читаемыми цветными карточками;
- мобильный список родственников и фотогалерея;
- Telegram Mini App;
- поиск людей;
- фильтрация по полу, месту, статусу, поколениям и родству;
- ближайшие дни рождения;
- команда `/birthdays`;
- диалоговые команды `/person` и `/family`;
- команды `/list`, `/photos`, `/grandchildren`, `/nephews`, `/me`, `/events` и `/stats`;
- отдельная семейная ветвь выбранного человека;
- вход на сайт через Telegram OpenID Connect;
- вход на сайт по личному логину и паролю;
- личное редактирование своей карточки, супруга, детей, альбомов и фото;
- повторяемый импорт GEDCOM с адресами и исходными данными;
- ежедневные уведомления о днях рождения;
- семейные Telegram-группы;
- ручное подтверждение пользователей;
- уведомления при заявках и изменениях доступа;
- разрешение, блокировка и назначение администраторов кнопками прямо в Telegram;
- ручное подтверждение групп;
- журнал входящих Telegram Update;
- административная панель Filament.

---

# Требования

- PHP 8.3+
- Composer
- Node.js 20+
- MySQL / MariaDB
- HTTPS-домен
- Telegram Bot

---

# Локальная разработка

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

Админка будет доступна по адресу:

```
http://127.0.0.1:8000/admin
```

Для просмотра Mini App без Telegram можно добавить в `.env`

```dotenv
TELEGRAM_DEV_USER_ID=1
```

---

# Развертывание на сервере (Plesk)

## 1. Настройка сайта

В настройках сайта Plesk необходимо изменить **Document Root**:

```
httpdocs/public
```

Это обязательно.

---

## 2. Клонирование проекта

Перейти в каталог сайта

```bash
cd /var/www/vhosts/ВАШ_ДОМЕН/httpdocs
```

Если сайт устанавливается впервые:

```bash
git clone https://github.com/VAtapin/atapin_bot.git .
```

Если проект уже установлен:

```bash
git pull
```

---

## 3. Установка зависимостей

Используйте PHP, установленный в Plesk.

Например:

```bash
/opt/plesk/php/8.5/bin/php /usr/lib/plesk-9.0/composer.phar install --no-dev --optimize-autoloader
```

---

## 4. Создание файла .env

```bash
cp .env.example .env
```

Минимальные настройки:

```dotenv
APP_NAME="Семейный архив"

APP_ENV=production
APP_DEBUG=false

APP_URL=https://example.com

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=atapin_bot
DB_USERNAME=atapin_bot
DB_PASSWORD=********

TELEGRAM_BOT_TOKEN=********
TELEGRAM_BOT_USERNAME=atapin_bot
TELEGRAM_WEBHOOK_SECRET=********
TELEGRAM_MINI_APP_URL=https://example.com/family
TELEGRAM_ADMIN_IDS=123456789

# BotFather → Bot Settings → Web Login
TELEGRAM_OIDC_CLIENT_ID=123456789
TELEGRAM_OIDC_CLIENT_SECRET=********
TELEGRAM_OIDC_REDIRECT_URI=https://example.com/auth/telegram/callback
```

---

## 5. Генерация APP_KEY

```bash
/opt/plesk/php/8.5/bin/php artisan key:generate
```

---

## 6. Создание структуры базы данных

Если база новая:

```bash
/opt/plesk/php/8.5/bin/php artisan migrate:fresh --seed --force
```

---

## 7. Создание ссылки Storage

Очень важный шаг.

Без него фотографии пользователей отображаться не будут.

```bash
/opt/plesk/php/8.5/bin/php artisan storage:link
```

---

## 8. Сборка фронтенда

```bash
npm install

npm run build
```

---

## 9. Создание администратора

```bash
/opt/plesk/php/8.5/bin/php artisan make:filament-user
```

После этого можно войти в

```
https://example.com/admin
```

---

## 10. Регистрация Telegram Webhook

```bash
/opt/plesk/php/8.5/bin/php artisan telegram:set-webhook
```

---

## 11. Настройка BotFather

Выполнить команду

```
/setdomain
```

Выбрать своего бота.

Указать домен

```
example.com
```

без

```
https://
```

и без

```
/family
```

В **Bot Settings → Web Login** добавьте:

- Allowed URL: `https://example.com`
- Redirect URI: `https://example.com/auth/telegram/callback`

Полученные Client ID и Client Secret внесите в `.env`. После первого входа
пользователь появится в разделе «Доступ пользователей». Привяжите его к
карточке человека и установите статус «Разрешён».

---

## 12. Настройка Cron

Добавить задачу:

```cron
* * * * * cd /var/www/vhosts/ВАШ_ДОМЕН/httpdocs && /opt/plesk/php/8.5/bin/php artisan schedule:run > /dev/null 2>&1
```

---

## 13. Node.js

Node.js используется **только** для сборки фронтенда.

После выполнения

```bash
npm install

npm run build
```

Node.js запускать не требуется.

Laravel работает через PHP.

Не нужно использовать

```
php artisan serve
```

на боевом сервере.

---

# Обновление проекта

После получения новой версии из GitHub выполнить:

```bash
git pull
```

```bash
/opt/plesk/php/8.5/bin/php /usr/lib/plesk-9.0/composer.phar install --no-dev --optimize-autoloader
```

```bash
npm install

npm run build
```

```bash
/opt/plesk/php/8.5/bin/php artisan optimize:clear
```

Если изменялись миграции:

```bash
/opt/plesk/php/8.5/bin/php artisan migrate
```

---

# Проверка

Проверить работу проекта:

- открыть

```
https://example.com/admin
```

- войти под созданным администратором;

- открыть Telegram Mini App;

- убедиться, что отображаются фотографии пользователей;

- проверить работу Telegram-бота;

- проверить команду

```
/birthdays
```

- убедиться, что Webhook зарегистрирован;

- убедиться, что Cron выполняется.

---

# Импорт GEDCOM

Файл можно положить в `storage/app/import`, например:

```bash
php artisan gedcom:import family.ged --dry-run
```

Команда сначала покажет, сколько людей, семей, мест и фотографий реально
присутствует в файле, не меняя базу.

Старый импорт не содержал GEDCOM ID. Поэтому для первого запуска нового
импортёра обязательно сделайте резервную копию базы, затем выполните:

```bash
php artisan gedcom:import family.ged --fresh --photos
```

`--fresh` заменяет людей и семейные связи, но сохраняет администраторов,
Telegram-пользователей и группы; привязки Telegram к карточкам людей будут
сброшены, потому что ID карточек создаются заново.

Последующие импорты выполняются без `--fresh`:

```bash
php artisan gedcom:import family.ged --photos
```

Они обновляют записи по `GEDCOM ID`, не создавая дублей. Импортируются:

- места рождения, смерти и захоронения;
- `RESI`, `ADDR`, `ADR1`, `CITY`, страна и индекс;
- фамилия в браке, профессия, заметки и все фотографии;
- даты и места брака/развода;
- полный исходный GEDCOM-блок каждой карточки в `gedcom_data`.

Импортёр не придумывает отсутствующие данные. В текущем `family.ged` есть
386 человек, 147 семей, 358 ссылок на медиа и 36 записей проживания, но
только 4 явных поля `CITY`. Фильтр Mini App дополнительно использует
`PLAC` из мест рождения, смерти и захоронения; отсутствующие в GEDCOM
города потребуется заполнить вручную.

---

# Важно

В Git **никогда** не должны попадать:

- `.env`
- токен Telegram-бота
- база данных
- каталог `storage/app/public`
- пользовательские фотографии
- служебные файлы сервера

Все эти данные должны храниться только на сервере.
