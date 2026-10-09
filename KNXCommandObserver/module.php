<?php
declare(strict_types=1);

class KNXCommandObserver extends IPSModule
{
    public function Create(): void
    {
        parent::Create();
        $this->RegisterPropertyBoolean('Active', false);
        $this->RegisterPropertyInteger('KnxGatewayID', 0);
        $this->RegisterPropertyString('SymconSourceAddress', '15.15.241');
        $this->RegisterPropertyInteger('RetentionDays', 3);
        $this->RegisterAttributeInteger('RegisteredGatewayID', 0);
        $this->RegisterAttributeString('CommandLog', '[]');
        $this->RegisterVariableString('Status', 'Status');
        $this->RegisterVariableString('LastCommand', 'Letztes KNX-Kommando');
        $this->RegisterTimer('RefreshDebugTimer', 0, 'KNO_RefreshDebug($_IPS["TARGET"]);');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        $old = $this->ReadAttributeInteger('RegisteredGatewayID');
        if ($old > 0 && IPS_InstanceExists($old)) {
            @$this->UnregisterMessage($old, KL_DEBUG);
        }
        $this->WriteAttributeInteger('RegisteredGatewayID', 0);

        if (!$this->ReadPropertyBoolean('Active')) {
            $this->SetTimerInterval('RefreshDebugTimer', 0);
            $this->setStatus('deaktiviert');
            $this->SetStatus(104);
            return;
        }

        $gateway = $this->resolveGatewayID();
        if ($gateway <= 0 || !IPS_InstanceExists($gateway)) {
            $this->SetTimerInterval('RefreshDebugTimer', 0);
            $this->setStatus('aktiv, aber kein KNX-Gateway gefunden');
            $this->SetStatus(201);
            return;
        }

        $this->RegisterMessage($gateway, KL_DEBUG);
        $this->WriteAttributeInteger('RegisteredGatewayID', $gateway);
        $this->SetTimerInterval('RefreshDebugTimer', 300000);
        $this->SetStatus(102);
        $this->RefreshDebug();
    }

    public function RefreshDebug(): void
    {
        if (!$this->ReadPropertyBoolean('Active')) return;
        $gateway = $this->ReadAttributeInteger('RegisteredGatewayID');
        if ($gateway <= 0 || !IPS_InstanceExists($gateway)) return;
        @IPS_EnableDebug($gateway, 600);
        $this->setStatus('aktiv · Gateway '.IPS_GetName($gateway).' (#'.$gateway.') · Symcon '.$this->ReadPropertyString('SymconSourceAddress'));
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data): void
    {
        if ($Message !== KL_DEBUG || !$this->ReadPropertyBoolean('Active')) return;
        if ((int)$SenderID !== $this->ReadAttributeInteger('RegisteredGatewayID')) return;

        $text = $this->flattenDebugData($Data);
        if ($text === '') return;

        if (!preg_match_all('~\\b(\\d{1,2}/\\d{1,2}/\\d{1,3})\\s*\\((\\d{1,2}\\.\\d{1,2}\\.\\d{1,3})\\)~', $text, $matches, PREG_SET_ORDER)) return;

        foreach ($matches as $m) {
            $ga = $m[1];
            $source = $m[2];
            $symconSource = trim($this->ReadPropertyString('SymconSourceAddress'));
            $classification = ($symconSource !== '' && $source === $symconSource) ? 'SYMCON_OUT' : 'KNX_DEVICE';
            $event = [
                'ts' => time(),
                'date' => date('Y-m-d'),
                'time' => date('H:i:s'),
                'groupAddress' => $ga,
                'sourceAddress' => $source,
                'classification' => $classification,
                'rawDebug' => mb_substr($text, 0, 1200)
            ];
            $this->appendCommand($event);
        }
    }

    public function GetEventsJSON(string $date = ''): string
    {
        if ($date === '') $date = date('Y-m-d');
        $events = array_values(array_filter($this->commandLog(), fn($e) => ($e['date'] ?? '') === $date));
        return json_encode([
            'schema' => 'KNXCommandObserver/1',
            'date' => $date,
            'generatedAt' => date(DATE_ATOM),
            'eventCount' => count($events),
            'events' => $events
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    public function GetRecentForGroupAddressJSON(string $groupAddress, int $withinSeconds = 30): string
    {
        $since = time() - max(1, $withinSeconds);
        $events = array_values(array_filter($this->commandLog(), fn($e) =>
            ($e['groupAddress'] ?? '') === $groupAddress && (int)($e['ts'] ?? 0) >= $since
        ));
        return json_encode([
            'groupAddress' => $groupAddress,
            'withinSeconds' => $withinSeconds,
            'eventCount' => count($events),
            'events' => $events
        ], JSON_UNESCAPED_UNICODE);
    }

    public function ClearLog(): void
    {
        $this->WriteAttributeString('CommandLog', '[]');
        SetValueString($this->GetIDForIdent('LastCommand'), '');
    }

    public function GetConfigurationForm(): string
    {
        $auto = $this->resolveGatewayID();
        $hint = $auto > 0 && IPS_InstanceExists($auto)
            ? 'Gateway: '.IPS_GetName($auto).' (#'.$auto.')'
            : 'Kein Gateway automatisch erkannt.';
        return json_encode([
            'elements' => [
                ['type' => 'CheckBox', 'name' => 'Active', 'caption' => 'Aktiv'],
                ['type' => 'SelectInstance', 'name' => 'KnxGatewayID', 'caption' => 'KNX-Gateway (0 = automatisch erkennen)'],
                ['type' => 'ValidationTextBox', 'name' => 'SymconSourceAddress', 'caption' => 'Physikalische KNX-Adresse von Symcon'],
                ['type' => 'NumberSpinner', 'name' => 'RetentionDays', 'caption' => 'Aufbewahrung in Tagen', 'minimum' => 1, 'maximum' => 14],
                ['type' => 'Label', 'caption' => 'Der Observer liest ausschließlich Gateway-Debugdaten und sendet keine KNX-Telegramme. '.$hint],
                ['type' => 'Label', 'caption' => 'Aus dem vorhandenen Gateway-Dump wurde 15.15.241 als Symcon-Quelladresse erkannt.']
            ],
            'actions' => [
                ['type' => 'Button', 'caption' => 'Debug jetzt erneuern', 'onClick' => 'KNO_RefreshDebug($id);'],
                ['type' => 'Button', 'caption' => 'Heutiges Protokoll anzeigen', 'onClick' => 'echo KNO_GetEventsJSON($id, "");'],
                ['type' => 'Button', 'caption' => 'Observer-Protokoll löschen', 'confirm' => 'Observer-Protokoll wirklich löschen?', 'onClick' => 'KNO_ClearLog($id);']
            ],
            'status' => [
                ['code' => 102, 'icon' => 'active', 'caption' => 'Aktiv'],
                ['code' => 104, 'icon' => 'inactive', 'caption' => 'Inaktiv'],
                ['code' => 201, 'icon' => 'error', 'caption' => 'Kein KNX-Gateway']
            ]
        ], JSON_UNESCAPED_UNICODE);
    }

    private function resolveGatewayID(): int
    {
        $configured = $this->ReadPropertyInteger('KnxGatewayID');
        if ($configured > 0 && IPS_InstanceExists($configured)) return $configured;

        foreach (IPS_GetInstanceList() as $id) {
            try {
                $instance = IPS_GetInstance($id);
                $module = IPS_GetModule($instance['ModuleInfo']['ModuleID'] ?? '');
                $name = (string)($module['ModuleName'] ?? '');
                if (stripos($name, 'KNX DPT') !== 0) continue;
                $connection = (int)($instance['ConnectionID'] ?? 0);
                if ($connection > 0 && IPS_InstanceExists($connection)) return $connection;
            } catch (Throwable $e) {
            }
        }
        return 0;
    }

    private function flattenDebugData(mixed $data): string
    {
        $parts = [];
        $walk = function ($value) use (&$walk, &$parts): void {
            if (is_string($value)) {
                if ($value !== '') $parts[] = $value;
                return;
            }
            if (is_int($value) || is_float($value)) {
                $parts[] = (string)$value;
                return;
            }
            if (is_array($value)) {
                foreach ($value as $k => $v) {
                    if (is_string($k)) $parts[] = $k;
                    $walk($v);
                }
            }
        };
        $walk($data);
        return trim(implode(' ', $parts));
    }

    private function appendCommand(array $event): void
    {
        $all = $this->commandLog();
        $fingerprint = ($event['groupAddress'] ?? '').'|'.($event['sourceAddress'] ?? '').'|'.($event['classification'] ?? '');
        $last = end($all);
        if (is_array($last)) {
            $lastFingerprint = ($last['groupAddress'] ?? '').'|'.($last['sourceAddress'] ?? '').'|'.($last['classification'] ?? '');
            if ($lastFingerprint === $fingerprint && abs((int)($event['ts'] ?? 0) - (int)($last['ts'] ?? 0)) <= 1) return;
        }

        $all[] = $event;
        $cut = time() - max(1, $this->ReadPropertyInteger('RetentionDays')) * 86400;
        $all = array_values(array_filter($all, fn($e) => (int)($e['ts'] ?? 0) >= $cut));
        if (count($all) > 10000) $all = array_slice($all, -10000);
        $this->WriteAttributeString('CommandLog', json_encode($all, JSON_UNESCAPED_UNICODE));

        $summary = ($event['classification'] ?? '?').' · '.($event['groupAddress'] ?? '?').' · '.($event['sourceAddress'] ?? '?');
        SetValueString($this->GetIDForIdent('LastCommand'), $summary);
    }

    private function commandLog(): array
    {
        $data = json_decode($this->ReadAttributeString('CommandLog'), true);
        return is_array($data) ? $data : [];
    }

    private function setStatus(string $text): void
    {
        SetValueString($this->GetIDForIdent('Status'), $text);
    }
}
