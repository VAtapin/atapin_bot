<?php

namespace App\Console\Commands;

use App\Models\FamilyTree;
use App\Models\User;
use App\Services\OwnerPersonService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class MakeSuperAdmin extends Command
{
    protected $signature = 'platform:make-super-admin
        {email : Email суперадминистратора}
        {--name= : Имя пользователя}
        {--password= : Новый пароль; для нового пользователя без опции создаётся автоматически}';

    protected $description = 'Создать или повысить пользователя до суперадминистратора платформы';

    public function handle(): int
    {
        $email = Str::lower(trim((string) $this->argument('email')));
        $user = User::query()->firstOrNew(['email' => $email]);
        $password = (string) $this->option('password');
        $generatedPassword = false;

        if (! $user->exists && $password === '') {
            $password = Str::password(20);
            $generatedPassword = true;
        }

        $user->fill([
            'name' => (string) ($this->option('name') ?: $user->name ?: Str::before($email, '@')),
            'is_active' => true,
            'is_super_admin' => true,
        ]);

        if ($password !== '') {
            $user->password = $password;
        }

        $user->save();

        $orphanTree = FamilyTree::query()->whereNull('owner_user_id')->oldest('id')->first();

        if ($orphanTree) {
            $orphanTree->update(['owner_user_id' => $user->id]);

            app(OwnerPersonService::class)->ensure($orphanTree, $user);

            $this->components->info("Дерево «{$orphanTree->name}» назначено этому владельцу.");
        }

        $this->components->info("Суперадминистратор {$email} готов.");

        if ($generatedPassword) {
            $this->line("Созданный пароль: {$password}");
            $this->components->warn('Сохраните пароль сейчас: повторно он не показывается.');
        }

        return self::SUCCESS;
    }
}
