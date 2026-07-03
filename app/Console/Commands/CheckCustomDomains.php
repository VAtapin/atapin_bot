<?php

namespace App\Console\Commands;

use App\Models\FamilyTree;
use App\Services\CustomDomainService;
use Illuminate\Console\Command;
use Throwable;

class CheckCustomDomains extends Command
{
    protected $signature = 'domains:check';

    protected $description = 'Verify configured custom family domains and TLS certificates';

    public function handle(CustomDomainService $domains): int
    {
        $checked = 0;
        FamilyTree::query()
            ->whereNotNull('primary_domain')
            ->whereIn('status', ['active', 'suspended'])
            ->each(function (FamilyTree $tree) use ($domains, &$checked): void {
                try {
                    if (! $tree->domain_verification_token) {
                        $tree = $domains->prepare($tree);
                    }
                    $domains->verify($tree);
                } catch (Throwable $exception) {
                    report($exception);
                    $tree->updateQuietly([
                        'domain_status' => 'error',
                        'domain_checked_at' => now(),
                        'domain_last_error' => mb_substr($exception->getMessage(), 0, 2000),
                    ]);
                }
                $checked++;
            });
        $this->info("Проверено доменов: {$checked}");

        return self::SUCCESS;
    }
}
