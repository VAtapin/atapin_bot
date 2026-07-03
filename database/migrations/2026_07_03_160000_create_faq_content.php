<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('faq_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_published')->default(true);
            $table->timestamps();
        });

        Schema::create('faq_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('faq_category_id')->constrained()->cascadeOnDelete();
            $table->string('question');
            $table->longText('answer');
            $table->string('keywords')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_published')->default(true);
            $table->timestamps();
        });

        $now = now();
        $categories = [
            ['title' => 'Быстрый старт', 'slug' => 'quick-start', 'description' => 'Как создать дерево с нуля и пригласить семью.', 'sort_order' => 10],
            ['title' => 'Люди и родственные связи', 'slug' => 'people-and-relations', 'description' => 'Карточки людей, супруги, родители и дети.', 'sort_order' => 20],
            ['title' => 'Доступ и безопасность', 'slug' => 'access-and-security', 'description' => 'Роли, приглашения, вход и защита аккаунта.', 'sort_order' => 30],
            ['title' => 'Импорт и фотографии', 'slug' => 'import-and-photos', 'description' => 'GEDCOM, фотографии, альбомы и резервные копии.', 'sort_order' => 40],
            ['title' => 'Собственный домен', 'slug' => 'custom-domain', 'description' => 'Подключение семейного адреса и HTTPS.', 'sort_order' => 50],
        ];

        foreach ($categories as $category) {
            DB::table('faq_categories')->insert([
                ...$category,
                'is_published' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $categoryIds = DB::table('faq_categories')->pluck('id', 'slug');
        $items = [
            ['quick-start', 'С чего начать новое семейное дерево?', '<p>Начните с себя и двигайтесь от известного к неизвестному:</p><ol><li>Откройте управление деревом и создайте свою карточку.</li><li>Добавьте родителей, затем братьев и сестёр.</li><li>Добавьте супруга или супругу и детей.</li><li>Проверьте связи в разделе дерева.</li><li>Загрузите основные фотографии.</li><li>Создайте приглашения для родственников и привяжите их аккаунты к карточкам.</li><li>После первой большой правки создайте резервную копию.</li></ol>', 'начать, новое дерево, с нуля, быстрый старт', 10],
            ['quick-start', 'Как открыть само дерево из панели управления?', '<p>В левом меню выберите «Открыть семейное дерево». Оно откроется как семейный сайт. Кнопка «Управление» вернёт владельца или модератора в административную часть.</p>', 'открыть дерево, просмотр, управление', 20],
            ['people-and-relations', 'Как правильно добавить ребёнка?', '<p>Сначала создайте карточку ребёнка, затем укажите родителей в связях карточки. Если известны оба родителя, добавьте две связи. Не создавайте вторую карточку одного и того же человека.</p>', 'ребёнок, родители, связь', 10],
            ['people-and-relations', 'Как исправить дубль человека?', '<p>Откройте список людей и используйте действие «Объединить дубль». Перед подтверждением внимательно выберите основную карточку: в неё будут перенесены связи и фотографии.</p>', 'дубль, объединить, повтор', 20],
            ['access-and-security', 'Чем отличаются владелец, модератор, член семьи и гость?', '<p><strong>Владелец</strong> управляет всем деревом, оплатой и администраторами. <strong>Модератор</strong> работает с семейными данными, но не меняет владельца и глобальные настройки. <strong>Член семьи</strong> может редактировать разрешённую семейную ветвь. <strong>Гость</strong> только просматривает доступные данные.</p>', 'роли, владелец, модератор, гость', 10],
            ['access-and-security', 'Как пригласить родственника без Telegram ID?', '<p>Создайте приглашение в разделе «Приглашения» и отправьте родственнику ссылку или QR-код. После входа подтвердите участника и привяжите его к нужной карточке человека.</p>', 'приглашение, telegram id, qr', 20],
            ['access-and-security', 'Как включить двухэтапную проверку?', '<p>Откройте страницу безопасности аккаунта, подключите приложение для одноразовых кодов и сохраните резервные коды. Для суперадминистратора двухэтапная проверка обязательна.</p>', '2fa, totp, безопасность, код', 30],
            ['import-and-photos', 'Как импортировать GEDCOM?', '<p>Сначала создайте резервную копию. Затем откройте «Импорт данных», выберите текстовый файл с расширением <code>.ged</code> или <code>.gedcom</code> и запустите проверку. После импорта проверьте людей без связей, дубли и фотографии.</p>', 'gedcom, ged, импорт', 10],
            ['import-and-photos', 'Почему фотография не отображается?', '<p>Проверьте квоту хранилища и наличие файла в хранилище дерева. После переноса сервера выполните создание ссылки хранилища и очистите кэш приложения. Если проблема остаётся, отправьте одно сообщение через кнопку «Сообщить об ошибке».</p>', 'фото, изображение, 429, хранилище', 20],
            ['custom-domain', 'Как подключить собственный домен?', '<p>Укажите домен в настройках дерева и сохраните изменения. Добавьте предложенные системой DNS-записи у регистратора. В Plesk подключите домен как алиас без перенаправления 301, выпустите сертификат Let’s Encrypt, затем вернитесь в настройки и запустите проверку.</p>', 'домен, dns, cname, plesk, https', 10],
        ];

        foreach ($items as [$slug, $question, $answer, $keywords, $sortOrder]) {
            DB::table('faq_items')->insert([
                'faq_category_id' => $categoryIds[$slug],
                'question' => $question,
                'answer' => $answer,
                'keywords' => $keywords,
                'sort_order' => $sortOrder,
                'is_published' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('faq_items');
        Schema::dropIfExists('faq_categories');
    }
};
