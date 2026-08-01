<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\ERPNextService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncCustomerToERPNext implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(public User $user) {}

    public function handle(ERPNextService $erp): void
    {
        if ($this->user->erp_customer_id) {
            return;
        }

        try {
            $result = $erp->callMethod('loyalpet.api.customers.create_customer', [
                'app_user_id' => (string) $this->user->id,
                'name' => $this->user->name,
                'phone' => $this->user->phone,
                'address' => $this->user->address ?? '',
            ]);

            $this->user->update([
                'erp_customer_id' => $result['message']['name'],
                'erp_sync_status' => 'synced',
                'erp_synced_at' => now(),
            ]);
        } catch (\Throwable $e) {
            $this->user->update(['erp_sync_status' => 'failed']);

            throw $e;
        }
    }
}
