<?php

$ru = require lang_path('ru/miniapp.php');

return array_replace_recursive($ru, [
    'title_suffix' => 'Я і дім мій', 'default_family_name' => 'Наша родина', 'default_family_subtitle' => 'Сімейна історія та пам’ять роду', 'unassociated_photo' => 'Фотографія без прив’язки', 'pair_names' => ':one і :two', 'family_member' => 'Член родини', 'crest_alt' => 'Сімейний герб', 'manage' => 'Керування', 'logout' => 'Вийти', 'sections' => 'Розділи',
    'tabs' => ['tree' => 'Дерево', 'list' => 'Список', 'gallery' => 'Фото', 'birthdays' => 'Дні народження', 'events' => 'Події', 'me' => 'Моя сім’я'],
    'filters' => [
        'heading' => 'Пошук і фільтри', 'close' => 'Закрити фільтри', 'search' => 'Знайти людину…', 'search_label' => 'Пошук',
        'gender' => 'Стать', 'gender_all' => 'Будь-яка стать', 'women' => 'Жінки', 'men' => 'Чоловіки', 'place' => 'Місце', 'places_all' => 'Усі місця',
        'status' => 'Статус', 'all' => 'Усі', 'living' => 'Живі', 'deceased' => 'Померлі', 'depth' => 'Глибина гілки',
        'generation_1' => '1 покоління', 'generation_2' => '2 покоління', 'generation_3' => '3 покоління', 'generation_4' => '4 покоління',
        'relation' => 'Спорідненість зі мною', 'relatives_all' => 'Усі родичі', 'parents' => 'Мої батьки', 'grandparents' => 'Мої дідусі й бабусі',
        'spouses' => 'Мій чоловік / дружина', 'children' => 'Мої діти', 'grandchildren' => 'Мої онуки', 'siblings' => 'Мої брати й сестри', 'nephews' => 'Мої племінники',
        'reset' => 'Скинути фільтри', 'apply' => 'Готово',
    ],
    'tree' => ['label' => 'Родинне дерево', 'zoom_out' => 'Зменшити', 'zoom_in' => 'Збільшити', 'fit' => 'Вписати гілку', 'mine' => 'Моя гілка', 'all' => 'Усе дерево', 'empty' => 'Нікого не знайдено', 'empty_hint' => 'Спробуйте змінити фільтри.'],
    'birthdays_intro' => 'Найближчі дні народження та річниці', 'gallery_more' => 'Показати ще', 'events_title' => 'Сімейні події', 'events_archive' => 'Минулі події', 'loading' => 'Завантаження',
    'issue' => ['button' => 'Повідомити про помилку', 'title' => 'Повідомити про помилку', 'text' => 'Опишіть, що потрібно перевірити або виправити.', 'subject' => 'Коротко опишіть помилку', 'details' => 'Подробиці', 'send' => 'Надіслати власнику дерева'],
    'congratulation' => ['title' => 'Привітати', 'message' => 'Напишіть теплі слова', 'send' => 'Надіслати привітання'],
    'auth' => ['title' => 'Вхід до сімейного архіву', 'text' => 'Використайте Telegram або особистий логін від адміністратора.', 'telegram' => 'Увійти через Telegram', 'or' => 'або', 'login' => 'Логін', 'password' => 'Пароль', 'submit' => 'Увійти', 'credentials' => 'Отримати логін і пароль у Telegram'],
    'js' => [
        'server_error' => 'Помилка сервера. Оновіть сторінку або спробуйте пізніше.', 'load_error' => 'Не вдалося завантажити дані', 'telegram_login' => 'Увійти через Telegram', 'born' => 'н. :date',
        'relations' => ['self' => 'Це ви', 'parents' => 'Батько / мати', 'grandparents' => 'Дідусь / бабуся', 'spouses' => 'Чоловік / дружина', 'children' => 'Дитина', 'grandchildren' => 'Онук / онука', 'siblings' => 'Брат / сестра', 'nephews' => 'Племінник / племінниця', 'relative' => 'Родич'],
        'empty_filter' => 'За вибраним фільтром нікого немає.', 'fields' => ['birth_date' => 'Дата народження', 'death_date' => 'Дата смерті', 'life_years' => 'Роки життя', 'maiden_name' => 'Дівоче прізвище', 'birth_place' => 'Місце народження', 'death_place' => 'Місце смерті', 'burial_place' => 'Місце поховання', 'city' => 'Місто', 'address' => 'Адреса', 'occupation' => 'Рід занять', 'parents' => 'Батьки', 'spouses' => 'Подружжя та партнери', 'children' => 'Діти', 'photos' => 'Фотографії'],
        'show_branch' => 'Показати родинну гілку', 'wrong_tree' => 'Сервер повернув інше родинне дерево. Оновіть сторінку.', 'birthdays' => 'Дні народження', 'shown' => 'Показано :shown із :total',
        'stale' => 'Не вдалося оновити дані. Показано останню завантажену версію.', 'all_places' => 'Усі місця', 'years' => ':count років', 'today' => 'сьогодні', 'in_days' => 'через :count дн.', 'congratulate' => 'Привітати',
        'add_calendar' => 'До календаря', 'birthday_calendar_title' => 'День народження: :name', 'anniversary_calendar_title' => 'Річниця: :name',
        'no_birthdays' => 'Дні народження ще не додано.', 'anniversaries' => 'Річниці', 'received' => 'Отримані привітання',
        'birthday_wish' => 'З днем народження! Бажаю здоров’я, радості й сімейного тепла!', 'anniversary_wish' => 'Вітаю з річницею! Бажаю любові, злагоди та багатьох щасливих років разом!',
        'no_photos' => 'Фотографій поки немає.', 'annual' => 'щороку', 'no_events' => 'Подій поки немає.', 'family_photo' => 'Сімейна фотографія', 'open_person' => 'Відкрити картку :name',
        'sent_telegram' => 'Збережено та надіслано в Telegram: :count.', 'saved_site' => 'Збережено на сімейному сайті. Telegram не підключений або недоступний.', 'sending' => 'Надсилаємо…',
        'editor' => [
            'last_name' => 'Прізвище', 'first_name' => 'Ім’я', 'middle_name' => 'По батькові', 'maiden_name' => 'Дівоче прізвище',
            'gender' => 'Стать', 'gender_unknown' => 'Не вказано', 'gender_male' => 'Чоловіча', 'gender_female' => 'Жіноча',
            'current_city' => 'Місто проживання', 'biography' => 'Біографія', 'spouse' => 'Чоловік / дружина', 'child' => 'Дитина', 'grandchild' => 'Онук / онука', 'child_spouse' => 'Зять / невістка',
            'readonly' => 'У вас гостьовий доступ. Дані доступні лише для перегляду.', 'your_profile' => 'Ваша картка в сімейному архіві', 'save_profile' => 'Зберегти мої дані', 'my_branch' => 'Моя сімейна гілка',
            'save' => 'Зберегти', 'unlink' => 'Видалити зв’язок', 'empty_relatives' => 'Поки нікого не додано.', 'add_relative' => 'Додати родича', 'relative_kind' => 'Кого додаємо?',
            'add_spouse' => 'Чоловіка / дружину', 'add_child' => 'Дитину', 'add_grandchild' => 'Онука / онуку', 'add_child_spouse' => 'Зятя / невістку',
            'through_child' => 'Через кого з дітей?', 'not_required' => 'Не потрібно', 'add_tree' => 'Додати до дерева',
            'albums' => 'Фотоальбоми', 'delete' => 'Видалити', 'no_albums' => 'Альбомів поки немає.', 'album_title' => 'Назва нового альбому', 'create' => 'Створити',
            'my_photos' => 'Мої фотографії', 'photo_caption' => 'Підпис до фотографії', 'no_album' => 'Без альбому', 'make_primary' => 'Зробити основною', 'upload' => 'Завантажити фотографію', 'primary' => 'Основна', 'first_photo' => 'Завантажте першу фотографію.',
            'delete_profile' => 'Видалення моєї картки', 'delete_profile_text' => 'Картку буде приховано, а Telegram відв’язано. Введіть «ВИДАЛИТИ».', 'delete_profile_button' => 'Видалити мою картку',
            'personal_data' => 'Мої персональні дані', 'personal_data_text' => 'Можна завантажити всі дані або повністю видалити обліковий запис.', 'download_data' => 'Завантажити мої дані',
            'delete_account_placeholder' => 'ВИДАЛИТИ АКАУНТ', 'delete_account' => 'Видалити акаунт', 'confirm_unlink' => 'Видалити сімейний зв’язок? Картка людини залишиться.',
            'confirm_album' => 'Видалити альбом? Фотографії залишаться.', 'confirm_photo' => 'Видалити фотографію?', 'confirm_profile' => 'Ця дія справді видалить вашу картку. Продовжити?',
        ],
    ],
]);
