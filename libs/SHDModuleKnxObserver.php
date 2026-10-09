<?php
declare(strict_types=1);

trait SHDModuleKnxObserver
{
    public function Create(): void
    {
        $this->CreateBase();
        $this->RegisterPropertyBoolean('KnxObserverEnabled', false);
        $this->RegisterPropertyInteger('KnxGatewayID', 0);
        $this->RegisterPropertyString('KnxSymconSourceAddress', '15.15.241');
        $this->RegisterAttributeInteger('KnxObserverRegisteredGateway', 0);
        $this->RegisterAttributeString('KnxCommandLog', '[]');
        $this->RegisterVariableString('KnxObserverStatus', 'KNX Command Observer');
        $this->RegisterTimer('KnxObserverRefreshTimer', 0, 'SHD_RefreshKnxObserver($_IPS["TARGET"]);');
    }

    public function ApplyChanges(): void
    {
        $this->ApplyChangesBase();
        $old = $this->ReadAttributeInteger('KnxObserverRegisteredGateway');
        if ($old > 0) {
            @ $this->UnregisterMessage($old, KL_DEBUG);
        }
        $this->WriteAttributeInteger('KnxObserverRegisteredGateway', 0);

        if (!$this->ReadPropertyBoolean('KnxObserverEnabled')) {
            $this->SetTimerInterval('KnxObserverRefreshTimer', 0);
            $this->setKnxObserverStatus('deaktiviert');
            return;
        }

        $gateway = $this->resolveKnxGatewayID();
        if ($gateway <= 0 || !IPS_InstanceExists($gateway)) {
            $this->SetTimerInterval('KnxObserverRefreshTimer', 0);
            $this->setKnxObserverStatus('aktiv, aber kein KNX-Gateway gefunden');
            return;
        }

        $this->RegisterMessage($gateway, KL_DEBUG);
        $this->WriteAttributeInteger('KnxObserverRegisteredGateway', $gateway);
        $this->SetTimerInterval('KnxObserverRefreshTimer', 300000);
        $this->RefreshKnxObserver();
    }

    public function RefreshKnxObserver(): void
    {
        if (!$this->ReadPropertyBoolean('KnxObserverEnabled')) return;
        $gateway = $this->ReadAttributeInteger('KnxObserverRegisteredGateway');
        if ($gateway <= 0 || !IPS_InstanceExists($gateway)) return;
        @IPS_EnableDebug($gateway, 600);
        $this->setKnxObserverStatus('aktiv · Gateway '.IPS_GetName($gateway).' (#'.$gateway.') · Symcon-Quelle '.$this->ReadPropertyString('KnxSymconSourceAddress'));
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data): void
    {
        if ($Message !== KL_DEBUG) return;
        if (!$this->ReadPropertyBoolean('KnxObserverEnabled')) return;
        if ((int)$SenderID !== $this->ReadAttributeInteger('KnxObserverRegisteredGateway')) return;

        $text = $this->flattenDebugData($Data);
        if ($text === '') return;

        if (!preg_match_all('~\b(\d{1,2}/\d{1,2}/\d{1,3})\s*\((\d{1,2}\.\d{1,2}\.\d{1,3})\)~', $text, $matches, PREG_SET_ORDER)) return;

        $gaMap = $this->knxObservedGroupAddresses();
        foreach ($matches as $m) {
            $ga = $m[1];
            if (!isset($gaMap[$ga])) continue;
            $source = $m[2];
            $symconSource = trim($this->ReadPropertyString('KnxSymconSourceAddress'));
            $classification = ($symconSource !== '' && $source === $symconSource) ? 'SYMCON_OUT' : 'KNX_DEVICE';
            $meta = $gaMap[$ga];
            $this->appendKnxCommand([
                'ts' => time(),
                'date' => date('Y-m-d'),
                'time' => date('H:i:s'),
                'groupAddress' => $ga,
                'sourceAddress' => $source,
                'classification' => $classification,
                'blind' => $meta['blind'] ?? null,
                'channel' => $meta['channel'] ?? null,
                'instanceID' => $meta['instanceID'] ?? 0,
                'rawDebug' => mb_substr($text, 0, 1000)
            ]);
        }
    }

    public function GetKnxObserverJSON(string $date = ''): string
    {
        if ($date === '') $date = date('Y-m-d');
        $events = array_values(array_filter($this->knxCommandLog(), fn($e) => ($e['date'] ?? '') === $date));
        return json_encode([
            'schema' => 'SmartShadingKnxObserver/1',
            'date' => $date,
            'generatedAt' => date(DATE_ATOM),
            'eventCount' => count($events),
            'events' => $events
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    public function GetDailyProtocolJSON(string $date = ''): string
    {
        if ($date === '') $date = date('Y-m-d');
        $base = json_decode($this->GetDailyProtocolJSONBase($date), true);
        if (!is_array($base)) $base = ['schema' => 'SmartShadingDayProtocol/1', 'date' => $date, 'events' => []];
        $commands = array_values(array_filter($this->knxCommandLog(), fn($e) => ($e['date'] ?? '') === $date));
        $base['knxCommandCount'] = count($commands);
        $base['knxCommands'] = $commands;
        return json_encode($base, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    public function GetConfigurationForm(): string
    {
        $raw = $this->GetConfigurationFormObserverBase();
        $form = json_decode($raw, true);
        if (!is_array($form)) return $raw;

        $auto = $this->resolveKnxGatewayID();
        $hint = $auto > 0 && IPS_InstanceExists($auto)
            ? 'Automatisch erkannt: '.IPS_GetName($auto).' (#'.$auto.').'
            : 'Kein Gateway automatisch erkannt.';

        $panel = [
            'type' => 'ExpansionPanel',
            'caption' => 'KNX Command Observer',
            'items' => [
                ['type' => 'Label', 'caption' => 'Beobachtet nur KNX-Debugtelegramme; greift nicht in Fahrbefehle ein. '.$hint],
                ['type' => 'CheckBox', 'name' => 'KnxObserverEnabled', 'caption' => 'KNX Command Observer aktivieren'],
                ['type' => 'SelectInstance', 'name' => 'KnxGatewayID', 'caption' => 'KNX-Gateway (0 = automatisch erkennen)'],
                ['type' => 'ValidationTextBox', 'name' => 'KnxSymconSourceAddress', 'caption' => 'Physikalische KNX-Adresse von Symcon'],
                ['type' => 'Label', 'caption' => 'Aktuell aus dem Gateway-Dump erkannt: 15.15.241. Andere Quelladressen werden als KNX-Gerät/Taster klassifiziert.']
            ]
        ];
        $form['elements'][] = $panel;
        return json_encode($form, JSON_UNESCAPED_UNICODE);
    }

    private function resolveKnxGatewayID(): int
    {
        $configured = $this->ReadPropertyInteger('KnxGatewayID');
        if ($configured > 0 && IPS_InstanceExists($configured)) return $configured;
        foreach ($this->blinds() as $b) {
            foreach (['positionControlID', 'slatControlID'] as $field) {
                $id = (int)($b[$field] ?? 0);
                if ($id <= 0 || !IPS_InstanceExists($id)) continue;
                try {
                    $instance = IPS_GetInstance($id);
                    $connection = (int)($instance['ConnectionID'] ?? 0);
                    if ($connection > 0 && IPS_InstanceExists($connection)) return $connection;
                } catch (Throwable $e) {
                }
            }
        }
        return 0;
    }

    private function knxObservedGroupAddresses(): array
    {
        $map = [];
        foreach ($this->blinds() as $b) {
            $name = (string)($b['name'] ?? '');
            foreach ([['positionControlID', 'position'], ['slatControlID', 'slat']] as [$field, $channel]) {
                $id = (int)($b[$field] ?? 0);
                $ga = $this->knxGroupAddressForInstance($id);
                if ($ga === null) continue;
                $map[$ga] = ['blind' => $name, 'channel' => $channel, 'instanceID' => $id];
            }
        }
        return $map;
    }

    private function knxGroupAddressForInstance(int $id): ?string
    {
        if ($id <= 0 || !IPS_InstanceExists($id)) return null;
        try {
            $cfg = json_decode(IPS_GetConfiguration($id), true);
            if (!is_array($cfg)) return null;
            if (!isset($cfg['Address1'], $cfg['Address2'], $cfg['Address3'])) return null;
            return (int)$cfg['Address1'].'/'.(int)$cfg['Address2'].'/'.(int)$cfg['Address3'];
        } catch (Throwable $e) {
            return null;
        }
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

    private function appendKnxCommand(array $event): void
    {
        $all = $this->knxCommandLog();
        $fingerprint = ($event['groupAddress'] ?? '').'|'.($event['sourceAddress'] ?? '').'|'.($event['channel'] ?? '');
        $last = end($all);
        if (is_array($last)) {
            $lastFingerprint = ($last['groupAddress'] ?? '').'|'.($last['sourceAddress'] ?? '').'|'.($last['channel'] ?? '');
            if ($lastFingerprint === $fingerprint && abs((int)($event['ts'] ?? 0) - (int)($last['ts'] ?? 0)) <= 1) return;
        }
        $all[] = $event;
        $cut = time() - max(1, $this->ReadPropertyInteger('ProtocolRetentionDays')) * 86400;
        $all = array_values(array_filter($all, fn($e) => (int)($e['ts'] ?? 0) >= $cut));
        if (count($all) > 5000) $all = array_slice($all, -5000);
        $this->WriteAttributeString('KnxCommandLog', json_encode($all, JSON_UNESCAPED_UNICODE));
        $this->setKnxObserverStatus(($event['classification'] ?? '?').' · '.($event['groupAddress'] ?? '?').' · '.($event['sourceAddress'] ?? '?').' · '.($event['blind'] ?? ''));
    }

    private function knxCommandLog(): array
    {
        $data = json_decode($this->ReadAttributeString('KnxCommandLog'), true);
        return is_array($data) ? $data : [];
    }

    private function setKnxObserverStatus(string $text): void
    {
        $id = $this->GetIDForIdent('KnxObserverStatus');
        if ($id > 0 && IPS_VariableExists($id)) SetValueString($id, $text);
    }
}
