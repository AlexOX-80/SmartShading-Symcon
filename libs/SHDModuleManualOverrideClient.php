<?php
declare(strict_types=1);

trait SHDModuleManualOverrideClient
{
    private function state(array $b, array $mem): array
    {
        $s = $this->stateWithShadow($b, $mem);
        $managerID = $this->manualOverrideManagerID();
        $s['manualOverrideManagerID'] = $managerID;
        $s['manualOverrideSource'] = null;

        if ($managerID <= 0 || !function_exists('MOM_RegisterDevice') || !function_exists('MOM_GetDeviceStateJSON')) {
            $s['manualOverride'] = null;
            return $s;
        }

        $deviceKey = $this->manualOverrideDeviceKey($b);
        $groups = [];
        foreach (['positionControlID', 'slatControlID'] as $field) {
            $ga = $this->manualOverrideKnxGroupAddress((int)($b[$field] ?? 0));
            if ($ga !== null) $groups[] = $ga;
        }
        $groups = array_values(array_unique($groups));
        $actionVariableID = $this->effectivePositionValueID($b);

        try {
            MOM_RegisterDevice(
                $managerID,
                $deviceKey,
                (string)($b['name'] ?? $deviceKey),
                json_encode($groups, JSON_UNESCAPED_UNICODE),
                $actionVariableID,
                0
            );
            $raw = MOM_GetDeviceStateJSON($managerID, $deviceKey);
            $state = json_decode($raw, true);
        } catch (Throwable $e) {
            $s['manualOverride'] = null;
            $s['manualOverrideError'] = $e->getMessage();
            return $s;
        }

        if (!is_array($state) || !($state['manualOverride'] ?? false)) {
            $s['manualOverride'] = null;
            return $s;
        }

        $s['manualOverrideSource'] = $state['source'] ?? null;
        $s['manualOverride'] = [
            'active' => true,
            'position' => $s['actualPosition'] ?? null,
            'slat' => $s['actualSlat'] ?? null,
            'source' => $state['source'] ?? null,
            'since' => $state['since'] ?? null,
            'expiresAt' => $state['expiresAt'] ?? null,
            'details' => $state['details'] ?? null
        ];
        return $s;
    }

    private function protocolEvent(string $k, array $e): array
    {
        $event = $this->protocolEventWithShadow($k, $e);
        $s = $e['state'] ?? [];
        if (!isset($event['context']) || !is_array($event['context'])) $event['context'] = [];
        $event['context']['manualOverrideManagerID'] = $s['manualOverrideManagerID'] ?? 0;
        $event['context']['manualOverrideSource'] = $s['manualOverrideSource'] ?? null;
        $event['context']['manualOverride'] = $s['manualOverride'] ?? null;
        return $event;
    }

    private function manualOverrideManagerID(): int
    {
        static $cached = null;
        if ($cached !== null && ($cached === 0 || IPS_InstanceExists($cached))) return $cached;
        $cached = 0;
        try {
            $ids = IPS_GetInstanceListByModuleID('{D1E5B9B0-4D2C-4A55-94A8-0B8F6F7D219C}');
            foreach ($ids as $id) {
                if (IPS_InstanceExists((int)$id)) return $cached = (int)$id;
            }
        } catch (Throwable $e) {
        }
        return $cached;
    }

    private function manualOverrideDeviceKey(array $b): string
    {
        $positionID = (int)($b['positionControlID'] ?? 0);
        if ($positionID > 0) return 'shading:knx:'.$positionID;
        $legacyID = (int)($b['blindID'] ?? 0);
        if ($legacyID > 0) return 'shading:legacy:'.$legacyID;
        return 'shading:name:'.sha1((string)($b['name'] ?? 'unknown'));
    }

    private function manualOverrideKnxGroupAddress(int $id): ?string
    {
        if ($id <= 0 || !IPS_InstanceExists($id)) return null;
        try {
            $cfg = json_decode(IPS_GetConfiguration($id), true);
            if (!is_array($cfg) || !isset($cfg['Address1'], $cfg['Address2'], $cfg['Address3'])) return null;
            return (int)$cfg['Address1'].'/'.(int)$cfg['Address2'].'/'.(int)$cfg['Address3'];
        } catch (Throwable $e) {
            return null;
        }
    }
}
