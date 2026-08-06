<?php

namespace App\Console\Commands;

use App\Models\DeliveryZone;
use App\Services\ERPNextService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('sync:delivery-zones')]
#[Description('Seed أولي لمناطق التوصيل من ERPNext — بعدها الويبهوك يتكفّل بالتحديثات')]
class SyncDeliveryZones extends Command
{
    public function handle(ERPNextService $erp): int
    {
        $result = $erp->callMethod('loyalpet.api.delivery_zones.get_delivery_zones');
        $zones = $result['message'] ?? $result;

        foreach ($zones as $zone) {
            // الميثود دي بترجع المناطق النشطة بس أصلًا
            DeliveryZone::updateOrCreate(
                ['erp_name' => $zone['name']],
                [
                    'zone_name' => $zone['zone_name'],
                    'delivery_price' => $zone['delivery_price'] ?? 0,
                    'is_active' => true,
                    'updated_at' => now(),
                ]
            );
        }

        $this->info('تمت مزامنة '.count($zones).' منطقة توصيل.');

        return self::SUCCESS;
    }
}
