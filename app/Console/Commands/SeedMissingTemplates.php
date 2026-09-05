<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Shop;
use App\Services\Email\DefaultEmailTemplateFactory;
use Illuminate\Console\Command;

class SeedMissingTemplates extends Command
{
    protected $signature = 'chargeguard:seed-missing-templates';

    protected $description = 'Create missing combinations without overwriting merchant edits';

    public function handle(DefaultEmailTemplateFactory $factory): int
    {
        Shop::query()->eachById(fn ($shop) => $factory->seed($shop));
        $this->info('Missing templates created. Existing templates were preserved.');

        return self::SUCCESS;
    }
}
