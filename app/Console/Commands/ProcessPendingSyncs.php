<?php

namespace App\Console\Commands;

use App\Jobs\SyncToERPNext;
use App\Models\PendingSync;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('sync:process')]
#[Description('احتياطي — يلتقط عمليات pending_syncs العالقة (لو الإنترنت قطع وقت الإنشاء) ويعيد إرسالها')]
class ProcessPendingSyncs extends Command
{
    public function handle(): int
    {
        $count = 0;

        PendingSync::whereIn('status', ['pending', 'failed'])
            ->where('attempts', '<', 3)
            ->orderBy('created_at')
            ->chunkById(50, function ($syncs) use (&$count) {
                foreach ($syncs as $sync) {
                    SyncToERPNext::dispatch($sync);
                    $count++;
                }
            });

        $this->info("تمت إعادة جدولة {$count} عملية.");

        return self::SUCCESS;
    }
}
