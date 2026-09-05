<?php
declare(strict_types=1);
namespace App\Jobs;
class ProcessDisputeUpdated extends ProcessDisputeCreated
{
    protected function initial(): bool { return false; }
}
