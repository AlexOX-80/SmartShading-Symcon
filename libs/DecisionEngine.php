<?php
declare(strict_types=1);

final class SHDPriority
{
    public const NONE = 0;
    public const COMFORT = 100;
    public const DAYLIGHT = 200;
    public const SOLAR_HEATING = 300;
    public const SUN_PROTECTION = 400;
    public const OVERHEATING = 500;
    public const PRIVACY = 600;
    public const MANUAL = 700;
    public const DOOR_PROTECTION = 800;
    public const FROST = 850;
    public const WIND = 900;
    public const FIRE = 1000;
}

final class SHDDecision
{
    public function __construct(
        public int $priority,
        public ?float $position,
        public ?float $slat,
        public string $reasonCode,
        public string $reason,
        public array $details = []
    ) {}

    public static function none(string $reason = 'No rule active'): self
    {
        return new self(SHDPriority::NONE, null, null, 'NONE', $reason);
    }

    public function toArray(): array
    {
        return [
            'priority' => $this->priority,
            'position' => $this->position,
            'slat' => $this->slat,
            'reasonCode' => $this->reasonCode,
            'reason' => $this->reason,
            'details' => $this->details
        ];
    }
}

final class SHDConfig
{
    public static function normalizeLegacy(array $raw, int $configVariableID = 0): array
    {
        $slatControl = $raw['ID_Lamellensteuerung']
            ?? $raw['ID Lamellensteuerung']
            ?? null;

        $cfg = self::normalizePublic([
            'source' => 'legacy',
            'configVariableID' => $configVariableID,
            'blindID' => self::positiveIntOrNull($raw['BehangID'] ?? null),
            'type' => ((int)($raw['Typ'] ?? 0) === 1) ? 'venetian' : 'roller',
            'facade' => self::intOrNull($raw['Fassade'] ?? null),
            'sunFrom' => self::angleOrNull($raw['Eintritt_Winkel'] ?? null),
            'sunTo' => self::angleOrNull($raw['Austritt_Winkel'] ?? null),
            'privacyDayPosition' => self::percentOrNull($raw['Sichschutz_Hoehe_Tag'] ?? null),
            'privacyDaySlat' => self::percentOrNull($raw['Sichschutz_Winkel_Tag'] ?? null),
            'privacyNightPosition' => self::percentOrNull($raw['Sichschutz_Hoehe_Nacht'] ?? null),
            'privacyNightSlat' => self::percentOrNull($raw['Sichschutz_Winkel_Nacht'] ?? null),
            'slatControlID' => self::positiveIntOrNull($slatControl),
            'roomTempID' => self::positiveIntOrNull($raw['TemperaturIstID'] ?? null),
            'roomSetpointID' => self::positiveIntOrNull($raw['TemperaturSollID'] ?? null),
        ]);

        if (array_key_exists('ID Lamellensteuerung', $raw)) {
            $cfg['warnings'][] = 'Legacy key "ID Lamellensteuerung" normalized.';
        }

        foreach ([
            'Sichschutz_Hoehe_Tag',
            'Sichschutz_Winkel_Tag',
            'Sichschutz_Hoehe_Nacht',
            'Sichschutz_Winkel_Nacht'
        ] as $field) {
            if (isset($raw[$field]) && ((float)$raw[$field] < 0 || (float)$raw[$field] > 100)) {
                $cfg['warnings'][] = $field . '=' . $raw[$field] . ' outside 0..100.';
            }
        }

        return $cfg;
    }

    public static function normalizePublic(array $raw): array
    {
        $defaults = [
            'schemaVersion' => 2,
            'source' => 'module',
            'configVariableID' => 0,
            'blindID' => 0,
            'name' => '',
            'enabled' => true,
            'type' => 'venetian',
            'room' => '',
            'facade' => null,
            'facadeAzimuth' => null,
            'sunFrom' => null,
            'sunTo' => null,
            'positionControlID' => 0,
            'positionStatusID' => 0,
            'slatControlID' => 0,
            'slatStatusID' => 0,
            'doorContactID' => 0,
            'roomTempID' => 0,
            'roomSetpointID' => 0,
            'privacyDayPosition' => null,
            'privacyDaySlat' => null,
            'privacyNightPosition' => 100.0,
            'privacyNightSlat' => 100.0,
            'warnings' => []
        ];

        $cfg = array_replace($defaults, $raw);
        $cfg['enabled'] = (bool)$cfg['enabled'];
        $cfg['blindID'] = (int)$cfg['blindID'];

        foreach ([
            'positionControlID', 'positionStatusID', 'slatControlID',
            'slatStatusID', 'doorContactID', 'roomTempID', 'roomSetpointID',
            'configVariableID'
        ] as $field) {
            $cfg[$field] = (int)($cfg[$field] ?? 0);
        }

        return $cfg;
    }

    private static function positiveIntOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '') return null;
        $value = (int)$value;
        return $value > 0 ? $value : null;
    }

    private static function intOrNull(mixed $value): ?int
    {
        return ($value === null || $value === '') ? null : (int)$value;
    }

    private static function angleOrNull(mixed $value): ?float
    {
        if ($value === null || $value === '') return null;
        $value = (float)$value;
        if ($value == 0.0) return null;
        $value = fmod($value, 360.0);
        return $value < 0 ? $value + 360.0 : $value;
    }

    private static function percentOrNull(mixed $value): ?float
    {
        if ($value === null || $value === '') return null;
        $value = (float)$value;
        return ($value >= 0.0 && $value <= 100.0) ? $value : null;
    }
}

final class SHDMath
{
    public static function signedAngle(float $degrees): float
    {
        $d = fmod($degrees + 180.0, 360.0);
        if ($d < 0) $d += 360.0;
        return $d - 180.0;
    }

    public static function facadeAzimuth(array $blind): ?float
    {
        if (($blind['facadeAzimuth'] ?? null) !== null && $blind['facadeAzimuth'] !== '') {
            return (float)$blind['facadeAzimuth'];
        }

        return match((int)($blind['facade'] ?? -1)) {
            0 => 0.0,
            1 => 90.0,
            2 => 180.0,
            3 => 270.0,
            default => null
        };
    }

    public static function directSun(array $blind, ?float $azimuth, ?float $elevation): bool
    {
        if ($azimuth === null || $elevation === null || $elevation <= 2.0) return false;

        $from = $blind['sunFrom'] ?? null;
        $to = $blind['sunTo'] ?? null;

        if ($from !== null && $to !== null) {
            $from = (float)$from;
            $to = (float)$to;
            if ($from <= $to) return $azimuth >= $from && $azimuth <= $to;
            return $azimuth >= $from || $azimuth <= $to;
        }

        $facade = self::facadeAzimuth($blind);
        return $facade !== null
            && abs(self::signedAngle($azimuth - $facade)) < 90.0;
    }

    public static function slat(float $sunElevation, float $relativeAzimuth): float
    {
        $e = max(0.0, min(90.0, $sunElevation));
        $factor = max(0.35, cos(deg2rad(min(89.0, abs($relativeAzimuth)))));
        $effective = $e * $factor;
        return round(max(18.0, min(85.0, 82.0 - 0.75 * $effective)), 1);
    }
}

final class SHDEngine
{
    public function decide(array $blind, array $state, array $memory = []): SHDDecision
    {
        $rules = [
            $this->wind($blind, $state),
            $this->frost($blind, $state),
            $this->door($blind, $state),
            $this->manual($state),
            $this->privacy($blind, $state),
            $this->overheat($blind, $state),
            $this->solarHeat($blind, $state),
            $this->sun($blind, $state, $memory)
        ];

        $rules = array_values(array_filter(
            $rules,
            static fn(SHDDecision $d): bool => $d->priority > SHDPriority::NONE
        ));

        if ($rules === []) return SHDDecision::none();

        usort($rules, static fn(SHDDecision $a, SHDDecision $b): int =>
            $b->priority <=> $a->priority
        );
        return $rules[0];
    }

    private function wind(array $blind, array $s): SHDDecision
    {
        if (!($s['windAlarm'] ?? false)) return SHDDecision::none();
        return new SHDDecision(
            SHDPriority::WIND, 0.0,
            ($blind['type'] ?? '') === 'venetian' ? 0.0 : null,
            'WIND', 'Wind alarm active.'
        );
    }

    private function frost(array $blind, array $s): SHDDecision
    {
        if (!($s['icingRisk'] ?? false)) return SHDDecision::none();
        return new SHDDecision(
            SHDPriority::FROST, 0.0,
            ($blind['type'] ?? '') === 'venetian' ? 0.0 : null,
            'FROST', 'Icing risk active.'
        );
    }

    private function door(array $blind, array $s): SHDDecision
    {
        if (!($s['doorOpen'] ?? false)) return SHDDecision::none();
        return new SHDDecision(
            SHDPriority::DOOR_PROTECTION, 0.0,
            ($blind['type'] ?? '') === 'venetian' ? 0.0 : null,
            'DOOR_OPEN', 'Associated door is open.'
        );
    }

    private function manual(array $s): SHDDecision
    {
        $m = $s['manualOverride'] ?? null;
        if (!is_array($m) || !($m['active'] ?? false)) return SHDDecision::none();

        return new SHDDecision(
            SHDPriority::MANUAL,
            isset($m['position']) ? (float)$m['position'] : null,
            isset($m['slat']) ? (float)$m['slat'] : null,
            'MANUAL', 'Manual override active.'
        );
    }

    private function privacy(array $blind, array $s): SHDDecision
    {
        if (!($s['isNight'] ?? false)) return SHDDecision::none();
        $position = $blind['privacyNightPosition'] ?? null;
        if ($position === null) return SHDDecision::none();

        return new SHDDecision(
            SHDPriority::PRIVACY,
            (float)$position,
            ($blind['type'] ?? '') === 'venetian' && ($blind['privacyNightSlat'] ?? null) !== null
                ? (float)$blind['privacyNightSlat'] : null,
            'PRIVACY_NIGHT',
            'Night privacy position.'
        );
    }

    private function overheat(array $blind, array $s): SHDDecision
    {
        if (!($s['directSun'] ?? false)) return SHDDecision::none();

        $room = $s['roomTemp'] ?? null;
        $setpoint = $s['roomSetpoint'] ?? null;
        if ($room === null || $setpoint === null) return SHDDecision::none();

        $risk = (float)$room - (float)$setpoint
            + max(0.0, (float)($s['roomTempTrendKPerH'] ?? 0.0) * 0.75);

        if (($s['outsideTemp'] ?? null) !== null && (float)$s['outsideTemp'] > (float)$room) {
            $risk += 0.4;
        }
        if ($risk < 0.6) return SHDDecision::none();

        return new SHDDecision(
            SHDPriority::OVERHEATING,
            72.0,
            $this->slatFor($blind, $s),
            'OVERHEATING',
            'Direct sun plus thermal overheating risk.',
            ['thermalRisk' => round($risk, 2)]
        );
    }

    private function solarHeat(array $blind, array $s): SHDDecision
    {
        if (!($s['directSun'] ?? false)) return SHDDecision::none();

        $room = $s['roomTemp'] ?? null;
        $setpoint = $s['roomSetpoint'] ?? null;
        $rad = $s['radiationFiltered'] ?? null;
        if ($room === null || $setpoint === null || $rad === null) return SHDDecision::none();

        if ((float)$room <= (float)$setpoint - 0.4 && (float)$rad >= 150.0) {
            return new SHDDecision(
                SHDPriority::SOLAR_HEATING,
                0.0,
                ($blind['type'] ?? '') === 'venetian' ? 0.0 : null,
                'SOLAR_HEATING',
                'Room below setpoint: use free solar heat.'
            );
        }
        return SHDDecision::none();
    }

    private function sun(array $blind, array $s, array $memory): SHDDecision
    {
        if (!($s['directSun'] ?? false)) return SHDDecision::none();

        $room = $s['roomTemp'] ?? null;
        $setpoint = $s['roomSetpoint'] ?? null;
        if ($room !== null && $setpoint !== null && (float)$room <= (float)$setpoint - 0.4) {
            return SHDDecision::none();
        }

        $rad = $s['radiationFiltered'] ?? null;
        if ($rad === null) return SHDDecision::none();

        $wasActive = (($memory['lastReasonCode'] ?? '') === 'SUN_PROTECTION');
        $on = (float)($s['sunOnThreshold'] ?? 300.0);
        $off = (float)($s['sunOffThreshold'] ?? 180.0);

        if (!$wasActive && (float)$rad < $on) return SHDDecision::none();
        if ($wasActive && (float)$rad < $off) return SHDDecision::none();

        return new SHDDecision(
            SHDPriority::SUN_PROTECTION,
            68.0,
            $this->slatFor($blind, $s),
            'SUN_PROTECTION',
            'Direct solar radiation above threshold.',
            ['radiation' => $rad, 'onThreshold' => $on, 'offThreshold' => $off]
        );
    }

    private function slatFor(array $blind, array $s): ?float
    {
        if (($blind['type'] ?? '') !== 'venetian') return null;
        return SHDMath::slat(
            (float)($s['sunElevation'] ?? 25.0),
            (float)($s['relativeSunAzimuth'] ?? 0.0)
        );
    }
}
