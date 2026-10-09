<?php
declare(strict_types=1);

trait SHDModuleKnxObserverClient
{
    public function GetDailyProtocolJSON(string $date = ''): string
    {
        if ($date === '') $date = date('Y-m-d');
        $base = json_decode($this->GetDailyProtocolJSONBase($date), true);
        if (!is_array($base)) return $this->GetDailyProtocolJSONBase($date);

        $observerID = $this->findKnxObserverInstanceID();
        if ($observerID <= 0 || !function_exists('KNO_GetEventsJSON')) {
            $base['knxObserver'] = ['available' => false];
            return json_encode($base, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }

        try {
            $observer = json_decode(KNO_GetEventsJSON($observerID, $date), true);
        } catch (Throwable $e) {
            $base['knxObserver'] = ['available' => true, 'instanceID' => $observerID, 'error' => $e->getMessage()];
            return json_encode($base, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }

        $events = is_array($observer['events'] ?? null) ? $observer['events'] : [];
        $base['knxObserver'] = [
            'available' => true,
            'instanceID' => $observerID,
            'eventCount' => count($events)
        ];
        $base['knxCommands'] = $events;
        return json_encode($base, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    private function findKnxObserverInstanceID(): int
    {
        $moduleID = '{B6D481E9-7B45-4C15-9A6C-0E6A6B1F7C22}';
        try {
            $ids = IPS_GetInstanceListByModuleID($moduleID);
            foreach ($ids as $id) {
                if (IPS_InstanceExists((int)$id)) return (int)$id;
            }
        } catch (Throwable $e) {
        }
        return 0;
    }
}
