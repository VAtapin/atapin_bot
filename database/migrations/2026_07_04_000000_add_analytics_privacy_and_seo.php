<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('privacy_accepted_at')->nullable()->after('remember_token');
            $table->string('privacy_policy_version', 30)->nullable()->after('privacy_accepted_at');
            $table->string('privacy_ip_hash', 64)->nullable()->after('privacy_policy_version');
        });

        Schema::table('cms_pages', function (Blueprint $table): void {
            $table->string('og_image_path')->nullable()->after('meta_description');
        });

        Schema::table('faq_categories', function (Blueprint $table): void {
            $table->string('locale', 10)->default('ru')->after('id');
            $table->dropUnique('faq_categories_slug_unique');
            $table->unique(['locale', 'slug']);
            $table->index(['locale', 'is_published']);
        });

        Schema::create('traffic_attributions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('visitor_id')->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('utm_source')->nullable();
            $table->string('utm_medium')->nullable();
            $table->string('utm_campaign')->nullable();
            $table->string('utm_content')->nullable();
            $table->string('utm_term')->nullable();
            $table->text('referrer')->nullable();
            $table->text('landing_page')->nullable();
            $table->timestamp('first_seen_at');
            $table->string('last_utm_source')->nullable();
            $table->string('last_utm_medium')->nullable();
            $table->string('last_utm_campaign')->nullable();
            $table->string('last_utm_content')->nullable();
            $table->string('last_utm_term')->nullable();
            $table->text('last_referrer')->nullable();
            $table->text('last_landing_page')->nullable();
            $table->timestamp('last_seen_at');
            $table->timestamps();
            $table->index(['user_id', 'first_seen_at']);
            $table->index(['utm_source', 'utm_campaign']);
        });

        Schema::create('analytics_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('event_uuid')->unique();
            $table->string('event_name', 80)->index();
            $table->string('deduplication_key', 190)->nullable()->unique();
            $table->uuid('visitor_id')->nullable()->index();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('tree_id')->nullable()->constrained('family_trees')->nullOnDelete();
            $table->foreignId('plan_id')->nullable()->constrained('plans')->nullOnDelete();
            $table->string('platform', 20)->default('web')->index();
            $table->text('landing_page')->nullable();
            $table->text('referrer')->nullable();
            $table->string('utm_source')->nullable()->index();
            $table->string('utm_medium')->nullable();
            $table->string('utm_campaign')->nullable()->index();
            $table->string('utm_content')->nullable();
            $table->string('utm_term')->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->string('ip_hash', 64)->nullable();
            $table->decimal('value', 12, 2)->nullable();
            $table->string('currency', 3)->nullable();
            $table->json('parameters')->nullable();
            $table->boolean('external_pending')->default(false)->index();
            $table->timestamp('external_dispatched_at')->nullable();
            $table->timestamp('occurred_at')->index();
            $table->timestamps();
            $table->index(['event_name', 'occurred_at']);
            $table->index(['tree_id', 'event_name']);
            $table->index(['user_id', 'event_name']);
        });

        $now = now();
        foreach ([
            ['analytics_ga4_id', 'GA4 Measurement ID', 'Например G-XXXXXXXXXX. Если пусто, Google Analytics не подключается.'],
            ['analytics_yandex_id', 'ID счётчика Яндекс Метрики', 'Только числовой ID. Если пусто, Метрика не подключается.'],
            ['analytics_vk_pixel_id', 'VK Ads Pixel ID', 'ID рекламного пикселя VK. Если пусто, пиксель не подключается.'],
        ] as [$key, $label, $description]) {
            DB::table('platform_settings')->updateOrInsert(
                ['key' => $key],
                [
                    'group' => 'analytics',
                    'value' => null,
                    'type' => 'string',
                    'is_secret' => false,
                    'label' => $label,
                    'description' => $description,
                    'sort_order' => 400,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }

        $this->seedLocalizedFaq();
        $this->seedLocalizedCms();
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_events');
        Schema::dropIfExists('traffic_attributions');

        Schema::table('faq_categories', function (Blueprint $table): void {
            $table->dropIndex(['locale', 'is_published']);
            $table->dropUnique(['locale', 'slug']);
            $table->dropColumn('locale');
            $table->unique('slug');
        });

        Schema::table('cms_pages', fn (Blueprint $table) => $table->dropColumn('og_image_path'));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn([
            'privacy_accepted_at',
            'privacy_policy_version',
            'privacy_ip_hash',
        ]));
    }

    private function seedLocalizedFaq(): void
    {
        $content = [
            'en' => [
                ['quick-start', 'Quick start', 'Build a family tree from scratch and invite relatives.', [
                    ['Where should I begin?', '<p>Start with yourself, add your parents, partner and children, then connect the relationships. Upload a few key photos, invite relatives and create a backup after the first major update.</p>', 'start,new tree,quick start'],
                    ['How do I open the tree itself?', '<p>Choose “Open family tree” in the management menu. The “Manage” button takes owners and moderators back to administration.</p>', 'open tree,view,manage'],
                ]],
                ['people-and-relations', 'People and relationships', 'Profiles, partners, parents and children.', [
                    ['How do I add a child correctly?', '<p>Create the child’s profile, then add links to each known parent. Do not create a second profile for the same person.</p>', 'child,parents,relationship'],
                    ['How do I merge a duplicate?', '<p>Open the people list and use “Merge duplicate”. Select the primary profile carefully: relationships and photos will be moved to it.</p>', 'duplicate,merge'],
                ]],
                ['access-and-security', 'Access and security', 'Roles, invitations, sign-in and account protection.', [
                    ['How are the roles different?', '<p>The owner manages the whole tree. A moderator manages family data. A family member edits their permitted branch. A guest has read-only access.</p>', 'roles,owner,moderator,guest'],
                    ['How can I invite someone without a Telegram ID?', '<p>Create an invitation link or QR code. After the relative signs in, approve the membership and link it to the correct person.</p>', 'invitation,telegram,qr'],
                    ['How do I enable two-factor authentication?', '<p>Open account security, connect an authenticator app and verify the one-time code.</p>', '2fa,totp,security'],
                ]],
                ['import-and-photos', 'Import and photos', 'GEDCOM, photos, albums and backups.', [
                    ['How do I import GEDCOM?', '<p>Create a backup, open Data Import, select a text file ending in .ged or .gedcom and start validation. Review duplicates and relationships afterwards.</p>', 'gedcom,ged,import'],
                    ['Why is a photo missing?', '<p>Check the storage quota and file availability. After a server move, recreate the storage link and clear the application cache.</p>', 'photo,image,storage'],
                ]],
                ['custom-domain', 'Custom domain', 'Connect a family address and HTTPS.', [
                    ['How do I connect my own domain?', '<p>Save the domain in tree settings, add the displayed DNS records, configure it in Plesk without a 301 redirect, issue a TLS certificate and run the DNS check.</p>', 'domain,dns,plesk,https'],
                ]],
            ],
            'de' => [
                ['quick-start', 'Schnellstart', 'Stammbaum von Grund auf erstellen und Angehörige einladen.', [
                    ['Womit soll ich beginnen?', '<p>Beginnen Sie mit sich selbst, ergänzen Sie Eltern, Partner und Kinder und verbinden Sie die Beziehungen. Laden Sie wichtige Fotos hoch, laden Sie Angehörige ein und erstellen Sie anschließend eine Sicherung.</p>', 'start,neuer stammbaum,schnellstart'],
                    ['Wie öffne ich den Stammbaum?', '<p>Wählen Sie in der Verwaltung „Familienstammbaum öffnen“. Über „Verwalten“ gelangen Eigentümer und Moderatoren zurück.</p>', 'stammbaum öffnen,ansicht,verwaltung'],
                ]],
                ['people-and-relations', 'Personen und Beziehungen', 'Profile, Partner, Eltern und Kinder.', [
                    ['Wie füge ich ein Kind richtig hinzu?', '<p>Erstellen Sie das Profil des Kindes und verbinden Sie anschließend alle bekannten Eltern. Legen Sie dieselbe Person nicht doppelt an.</p>', 'kind,eltern,beziehung'],
                    ['Wie führe ich Duplikate zusammen?', '<p>Öffnen Sie die Personenliste und wählen Sie „Duplikat zusammenführen“. Beziehungen und Fotos werden in das Hauptprofil übernommen.</p>', 'duplikat,zusammenführen'],
                ]],
                ['access-and-security', 'Zugriff und Sicherheit', 'Rollen, Einladungen, Anmeldung und Kontoschutz.', [
                    ['Was bedeuten die Rollen?', '<p>Der Eigentümer verwaltet alles. Moderatoren bearbeiten Familiendaten. Familienmitglieder bearbeiten freigegebene Zweige. Gäste dürfen nur lesen.</p>', 'rollen,eigentümer,moderator,gast'],
                    ['Wie lade ich ohne Telegram-ID ein?', '<p>Erstellen Sie einen Einladungslink oder QR-Code. Bestätigen Sie die Person nach der Anmeldung und verbinden Sie sie mit dem richtigen Profil.</p>', 'einladung,telegram,qr'],
                    ['Wie aktiviere ich die Zwei-Faktor-Anmeldung?', '<p>Öffnen Sie die Kontosicherheit, verbinden Sie eine Authenticator-App und bestätigen Sie den Einmalcode.</p>', '2fa,totp,sicherheit'],
                ]],
                ['import-and-photos', 'Import und Fotos', 'GEDCOM, Fotos, Alben und Sicherungen.', [
                    ['Wie importiere ich GEDCOM?', '<p>Erstellen Sie zuerst eine Sicherung. Wählen Sie unter Datenimport eine Datei mit .ged oder .gedcom und prüfen Sie anschließend Duplikate und Beziehungen.</p>', 'gedcom,ged,import'],
                    ['Warum wird ein Foto nicht angezeigt?', '<p>Prüfen Sie Speicherplatz und Datei. Nach einem Serverumzug müssen der Storage-Link neu erstellt und der Cache geleert werden.</p>', 'foto,bild,speicher'],
                ]],
                ['custom-domain', 'Eigene Domain', 'Familienadresse und HTTPS verbinden.', [
                    ['Wie verbinde ich eine eigene Domain?', '<p>Speichern Sie die Domain, setzen Sie die angezeigten DNS-Einträge, richten Sie sie in Plesk ohne 301-Weiterleitung ein, stellen Sie ein TLS-Zertifikat aus und starten Sie die DNS-Prüfung.</p>', 'domain,dns,plesk,https'],
                ]],
            ],
            'uk' => [
                ['quick-start', 'Швидкий старт', 'Створення родинного дерева з нуля та запрошення рідних.', [
                    ['З чого почати?', '<p>Почніть із себе, додайте батьків, чоловіка або дружину та дітей, а потім установіть зв’язки. Завантажте основні фотографії, запросіть рідних і створіть резервну копію.</p>', 'початок,нове дерево,швидкий старт'],
                    ['Як відкрити саме дерево?', '<p>Виберіть «Відкрити родинне дерево» в меню керування. Кнопка «Керування» повертає власника або модератора до адміністративної частини.</p>', 'відкрити дерево,перегляд,керування'],
                ]],
                ['people-and-relations', 'Люди та родинні зв’язки', 'Картки, подружжя, батьки й діти.', [
                    ['Як правильно додати дитину?', '<p>Створіть картку дитини, потім додайте зв’язки з усіма відомими батьками. Не створюйте другу картку тієї самої людини.</p>', 'дитина,батьки,зв’язок'],
                    ['Як об’єднати дубль?', '<p>Відкрийте список людей і виберіть «Об’єднати дубль». Зв’язки та фотографії буде перенесено до основної картки.</p>', 'дубль,об’єднання'],
                ]],
                ['access-and-security', 'Доступ і безпека', 'Ролі, запрошення, вхід і захист облікового запису.', [
                    ['Чим відрізняються ролі?', '<p>Власник керує всім деревом. Модератор працює із сімейними даними. Член родини редагує дозволену гілку. Гість лише переглядає.</p>', 'ролі,власник,модератор,гість'],
                    ['Як запросити без Telegram ID?', '<p>Створіть посилання-запрошення або QR-код. Після входу підтвердьте учасника та прив’яжіть його до правильної картки.</p>', 'запрошення,telegram,qr'],
                    ['Як увімкнути двофакторний захист?', '<p>Відкрийте безпеку облікового запису, підключіть застосунок-автентифікатор і підтвердьте одноразовий код.</p>', '2fa,totp,безпека'],
                ]],
                ['import-and-photos', 'Імпорт і фотографії', 'GEDCOM, фотографії, альбоми та резервні копії.', [
                    ['Як імпортувати GEDCOM?', '<p>Спочатку створіть резервну копію. У розділі імпорту виберіть файл .ged або .gedcom, а після завершення перевірте дублікати та зв’язки.</p>', 'gedcom,ged,імпорт'],
                    ['Чому фотографія не відображається?', '<p>Перевірте квоту сховища та наявність файла. Після перенесення сервера повторно створіть storage link і очистьте кеш.</p>', 'фото,зображення,сховище'],
                ]],
                ['custom-domain', 'Власний домен', 'Підключення сімейної адреси та HTTPS.', [
                    ['Як підключити власний домен?', '<p>Збережіть домен у налаштуваннях, додайте показані DNS-записи, налаштуйте його в Plesk без перенаправлення 301, випустіть TLS-сертифікат і запустіть перевірку DNS.</p>', 'домен,dns,plesk,https'],
                ]],
            ],
        ];
        $now = now();
        foreach ($content as $locale => $categories) {
            foreach ($categories as [$slug, $title, $description, $items]) {
                $categoryId = DB::table('faq_categories')->insertGetId([
                    'locale' => $locale,
                    'title' => $title,
                    'slug' => $slug,
                    'description' => $description,
                    'sort_order' => match ($slug) {
                        'quick-start' => 10,
                        'people-and-relations' => 20,
                        'access-and-security' => 30,
                        'import-and-photos' => 40,
                        default => 50,
                    },
                    'is_published' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                foreach ($items as $index => [$question, $answer, $keywords]) {
                    DB::table('faq_items')->insert([
                        'faq_category_id' => $categoryId,
                        'question' => $question,
                        'answer' => $answer,
                        'keywords' => $keywords,
                        'sort_order' => ($index + 1) * 10,
                        'is_published' => true,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }
        }
    }

    private function seedLocalizedCms(): void
    {
        $pages = [
            'en' => [
                ['about', 'About', 'About “Me and my household”', 'A private service for family trees and preserving family history.', '<p>“Me and my household” helps families preserve relationships, photographs, biographies, documents and important dates in a private archive.</p>'],
                ['contacts', 'Contact', 'Contact — Me and my household', 'How to contact the “Me and my household” project.', '<p>Use the support contact published by the platform administrator. Do not send passwords or family documents by email.</p>'],
                ['impressum', 'Legal notice', 'Legal notice — Me and my household', 'Legal information about the operator of the service.', '<p>Information about the operator of the service is published on this page.</p>'],
                ['datenschutz', 'Privacy policy', 'Privacy policy — Me and my household', 'Information about personal data processing and privacy.', '<h2>Purpose of processing</h2><p>Account and family data is processed to provide the private family archive. Advertising analytics is loaded only after separate consent.</p><h2>Your rights</h2><p>You may download or delete your personal account data from your account settings. Family tree access is controlled by its owner.</p>'],
            ],
            'de' => [
                ['about', 'Über das Projekt', 'Über „Ich und mein Haus“', 'Ein privater Dienst für Stammbäume und Familienerinnerungen.', '<p>„Ich und mein Haus“ unterstützt Familien dabei, Beziehungen, Fotos, Biografien, Dokumente und wichtige Daten in einem privaten Archiv zu bewahren.</p>'],
                ['contacts', 'Kontakt', 'Kontakt — Ich und mein Haus', 'Kontaktmöglichkeiten für das Projekt „Ich und mein Haus“.', '<p>Nutzen Sie die von der Plattform veröffentlichte Support-Adresse. Senden Sie keine Passwörter oder Familiendokumente per E-Mail.</p>'],
                ['impressum', 'Impressum', 'Impressum — Ich und mein Haus', 'Rechtliche Angaben zum Betreiber des Dienstes.', '<p>Die Angaben zum Betreiber des Dienstes werden auf dieser Seite veröffentlicht.</p>'],
                ['datenschutz', 'Datenschutz', 'Datenschutz — Ich und mein Haus', 'Informationen zur Verarbeitung personenbezogener Daten.', '<h2>Zweck der Verarbeitung</h2><p>Konto- und Familiendaten werden zur Bereitstellung des privaten Familienarchivs verarbeitet. Werbe- und Webanalyse wird erst nach gesonderter Einwilligung geladen.</p><h2>Ihre Rechte</h2><p>Sie können Ihre Kontodaten in den Einstellungen herunterladen oder löschen. Der Eigentümer steuert den Zugriff auf den Familienstammbaum.</p>'],
            ],
            'uk' => [
                ['about', 'Про проєкт', 'Про «Я і дім мій»', 'Приватний сервіс для родинних дерев і збереження сімейної історії.', '<p>«Я і дім мій» допомагає родинам зберігати зв’язки, фотографії, біографії, документи та важливі дати у приватному архіві.</p>'],
                ['contacts', 'Контакти', 'Контакти — Я і дім мій', 'Як зв’язатися з проєктом «Я і дім мій».', '<p>Використовуйте адресу підтримки, опубліковану адміністратором платформи. Не надсилайте паролі чи сімейні документи електронною поштою.</p>'],
                ['impressum', 'Правова інформація', 'Правова інформація — Я і дім мій', 'Відомості про оператора сервісу.', '<p>Відомості про оператора сервісу публікуються на цій сторінці.</p>'],
                ['datenschutz', 'Політика конфіденційності', 'Політика конфіденційності — Я і дім мій', 'Інформація про обробку персональних даних і конфіденційність.', '<h2>Мета обробки</h2><p>Дані облікового запису та родини обробляються для роботи приватного сімейного архіву. Рекламна аналітика завантажується лише після окремої згоди.</p><h2>Ваші права</h2><p>Ви можете завантажити або видалити дані облікового запису в налаштуваннях. Доступ до дерева контролює його власник.</p>'],
            ],
        ];
        foreach ($pages as $locale => $localizedPages) {
            foreach ($localizedPages as $sortOrder => [$slug, $title, $metaTitle, $metaDescription, $content]) {
                if (DB::table('cms_pages')->where(['locale' => $locale, 'slug' => $slug])->exists()) {
                    continue;
                }
                DB::table('cms_pages')->insert([
                    'locale' => $locale,
                    'slug' => $slug,
                    'title' => $title,
                    'meta_title' => $metaTitle,
                    'meta_description' => $metaDescription,
                    'content' => $content,
                    'status' => 'published',
                    'is_published' => true,
                    'sort_order' => ($sortOrder + 1) * 10,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }
};
