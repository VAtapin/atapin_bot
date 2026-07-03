<?php

namespace App\Services;

use App\Models\PlatformSetting;
use Illuminate\Support\Facades\Schema;
use Throwable;

class PlatformMailConfigurator
{
    public function apply(): bool
    {
        try {
            if (! Schema::hasTable('platform_settings') || ! PlatformSetting::value('smtp_enabled', false)) {
                return false;
            }

            $encryption = mb_strtolower((string) PlatformSetting::value('smtp_encryption', 'tls'));
            config([
                'mail.default' => 'smtp',
                'mail.mailers.smtp.host' => PlatformSetting::value('smtp_host'),
                'mail.mailers.smtp.port' => PlatformSetting::value('smtp_port', 587),
                'mail.mailers.smtp.scheme' => $encryption === 'ssl' ? 'smtps' : 'smtp',
                'mail.mailers.smtp.auto_tls' => $encryption === 'tls',
                'mail.mailers.smtp.username' => PlatformSetting::value('smtp_username'),
                'mail.mailers.smtp.password' => PlatformSetting::value('smtp_password'),
                'mail.mailers.smtp.timeout' => PlatformSetting::value('smtp_timeout', 15),
                'mail.from.address' => PlatformSetting::value('smtp_from_address', config('mail.from.address')),
                'mail.from.name' => PlatformSetting::value('smtp_from_name', config('mail.from.name')),
            ]);

            app('mail.manager')->forgetMailers();

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
