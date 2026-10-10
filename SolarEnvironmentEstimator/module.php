<?php
declare(strict_types=1);

class SolarEnvironmentEstimator extends IPSModule
{
    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyBoolean('Active', true);
        $this->RegisterPropertyInteger('EvaluationInterval', 30);
        $this->RegisterPropertyInteger('PVPowerID', 0);
        $this->RegisterPropertyInteger('BrightnessID', 0);
        $this->RegisterPropertyInteger('SunAzimuthID', 0);
        $this->RegisterPropertyInteger('SunElevationID', 0);

        $this->RegisterPropertyFloat('PVNominalPowerKWp', 10.0);
        $this->RegisterPropertyFloat('PVPerformanceFactor', 0.90);
        $this->RegisterPropertyFloat('PVAzimuth', 180.0);
        $this->RegisterPropertyFloat('PVTilt', 30.0);
        $this->RegisterPropertyFloat('LuxPerWm2', 120.0);
        $this->RegisterPropertyBoolean('PVPowerAbsolute', true);

        $this->RegisterVariableFloat('GlobalRadiationEstimated', 'Globalstrahlung geschätzt [W/m²]');
        $this->RegisterVariableFloat('DirectSunProbability', 'Direktsonnen-Wahrscheinlichkeit [%]');
        $this->RegisterVariableFloat('RadiationNorth', 'Fassadenstrahlung Nord [W/m²]');
        $this->RegisterVariableFloat('RadiationEast', 'Fassadenstrahlung Ost [W/m²]');
        $this->RegisterVariableFloat('RadiationSouth', 'Fassadenstrahlung Süd [W/m²]');
        $this->RegisterVariableFloat('RadiationWest', 'Fassadenstrahlung West [W/m²]');
        $this->RegisterVariableString('Quality', 'Qualität');
        $this->RegisterVariableString('Diagnostics', 'Diagnose');
        $this->RegisterVariableInteger('LastEvaluation', 'Letzte Auswertung', '~UnixTimestamp');

        $this->RegisterTimer('EvaluateTimer', 0, 'SEE_Evaluate($_IPS["TARGET"]);');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $active = $this->ReadPropertyBoolean('Active');
        $interval = max(10, $this->ReadPropertyInteger('EvaluationInterval')) * 1000;
        $this->SetTimerInterval('EvaluateTimer', $active ? $interval : 0);
        $this->SetStatus($active ? 102 : 104);

        // Deliberately no synchronous Evaluate() here. Module updates must stay cheap and safe.
    }

    public function Evaluate(): void
    {
        if (!$this->ReadPropertyBoolean('Active')) {
            return;
        }

        $pv = $this->readNumeric($this->ReadPropertyInteger('PVPowerID'));
        $lux = $this->readNumeric($this->ReadPropertyInteger('BrightnessID'));
        $az = $this->readNumeric($this->ReadPropertyInteger('SunAzimuthID'));
        $el = $this->readNumeric($this->ReadPropertyInteger('SunElevationID'));

        $available = 0;
        if ($pv !== null) $available++;
        if ($lux !== null) $available++;
        if ($az !== null) $available++;
        if ($el !== null) $available++;

        if ($pv === null || $lux === null || $az === null || $el === null) {
            $this->writeIfChangedString('Quality', $available >= 2 ? 'DEGRADED' : 'NO_DATA');
            $this->writeIfChangedString('Diagnostics', json_encode([
                'pvPowerW' => $pv,
                'lux' => $lux,
                'sunAzimuth' => $az,
                'sunElevation' => $el,
                'missingInputs' => [
                    'PVPowerID' => $pv === null,
                    'BrightnessID' => $lux === null,
                    'SunAzimuthID' => $az === null,
                    'SunElevationID' => $el === null
                ]
            ], JSON_UNESCAPED_UNICODE));
            $this->writeIfChangedInt('LastEvaluation', time());
            return;
        }

        if ($this->ReadPropertyBoolean('PVPowerAbsolute')) {
            $pv = abs($pv);
        }
        $pv = max(0.0, $pv);
        $lux = max(0.0, $lux);
        $az = $this->normalizeAngle($az);
        $el = max(-10.0, min(90.0, $el));

        $nominalW = max(100.0, $this->ReadPropertyFloat('PVNominalPowerKWp') * 1000.0);
        $performance = max(0.50, min(1.10, $this->ReadPropertyFloat('PVPerformanceFactor')));
        $luxPerWm2 = max(50.0, min(250.0, $this->ReadPropertyFloat('LuxPerWm2')));

        $pvPlaneRadiation = min(1300.0, ($pv / ($nominalW * $performance)) * 1000.0);
        $luxRadiation = min(1300.0, $lux / $luxPerWm2);

        $pvCos = $this->surfaceIncidenceCosine(
            $az,
            $el,
            $this->normalizeAngle($this->ReadPropertyFloat('PVAzimuth')),
            max(0.0, min(90.0, $this->ReadPropertyFloat('PVTilt')))
        );

        $sinEl = max(0.0, sin(deg2rad(max(0.0, $el))));
        $clearPlane = max(60.0, (900.0 * max(0.0, $pvCos)) + (120.0 * $sinEl));
        $pvClearRatio = $pvPlaneRadiation / $clearPlane;
        $pvScore = $this->clamp01(($pvClearRatio - 0.18) / 0.52);

        $clearLux = max(3000.0, 110000.0 * pow(max(0.02, $sinEl), 0.70));
        $luxClearRatio = $lux / $clearLux;
        $luxScore = $this->clamp01(($luxClearRatio - 0.15) / 0.55);

        // Two independent local signals. Either one may be disadvantaged by installation geometry,
        // therefore the stronger signal still contributes meaningfully without overruling the other.
        $agreement = min($pvScore, $luxScore);
        $strongest = max($pvScore, $luxScore);
        $directProbability = $this->clamp01((0.35 * $pvScore) + (0.35 * $luxScore) + (0.20 * $strongest) + (0.10 * $agreement));
        if ($el <= 0.0 || ($pv < 20.0 && $lux < 500.0)) {
            $directProbability = 0.0;
        }

        // PV is the primary energy signal; lux only stabilizes the estimate when PV geometry is weak.
        $globalRadiation = min(1300.0, max(0.0, (0.75 * $pvPlaneRadiation) + (0.25 * $luxRadiation)));

        $diffuseHorizontal = $globalRadiation * (1.0 - $directProbability);
        $dni = 0.0;
        if ($el > 0.0) {
            $dni = min(1100.0, ($globalRadiation * $directProbability) / max(0.20, $sinEl));
        }

        $north = $this->facadeRadiation($az, $el, 0.0, $dni, $diffuseHorizontal);
        $east  = $this->facadeRadiation($az, $el, 90.0, $dni, $diffuseHorizontal);
        $south = $this->facadeRadiation($az, $el, 180.0, $dni, $diffuseHorizontal);
        $west  = $this->facadeRadiation($az, $el, 270.0, $dni, $diffuseHorizontal);

        $quality = 'GOOD';
        if ($el <= 0.0) {
            $quality = 'NIGHT';
        } elseif ($pvCos < 0.08 && $pvPlaneRadiation < 80.0) {
            $quality = 'ESTIMATED';
        }

        $this->writeIfChangedFloat('GlobalRadiationEstimated', round($globalRadiation, 1), 0.5);
        $this->writeIfChangedFloat('DirectSunProbability', round($directProbability * 100.0, 1), 0.5);
        $this->writeIfChangedFloat('RadiationNorth', round($north, 1), 0.5);
        $this->writeIfChangedFloat('RadiationEast', round($east, 1), 0.5);
        $this->writeIfChangedFloat('RadiationSouth', round($south, 1), 0.5);
        $this->writeIfChangedFloat('RadiationWest', round($west, 1), 0.5);
        $this->writeIfChangedString('Quality', $quality);
        $this->writeIfChangedString('Diagnostics', json_encode([
            'pvPowerW' => round($pv, 1),
            'lux' => round($lux, 1),
            'sunAzimuth' => round($az, 1),
            'sunElevation' => round($el, 1),
            'pvPlaneRadiation' => round($pvPlaneRadiation, 1),
            'luxRadiation' => round($luxRadiation, 1),
            'pvIncidenceCosine' => round($pvCos, 3),
            'pvClearRatio' => round($pvClearRatio, 3),
            'luxClearRatio' => round($luxClearRatio, 3),
            'pvScore' => round($pvScore, 3),
            'luxScore' => round($luxScore, 3),
            'directProbability' => round($directProbability, 3),
            'dniEstimated' => round($dni, 1),
            'diffuseHorizontalEstimated' => round($diffuseHorizontal, 1)
        ], JSON_UNESCAPED_UNICODE));
        $this->writeIfChangedInt('LastEvaluation', time());
    }

    public function GetConfigurationForm(): string
    {
        return json_encode([
            'elements' => [
                ['type' => 'CheckBox', 'name' => 'Active', 'caption' => 'Aktiv'],
                ['type' => 'NumberSpinner', 'name' => 'EvaluationInterval', 'caption' => 'Auswertungsintervall in Sekunden', 'minimum' => 10, 'maximum' => 300],
                ['type' => 'ExpansionPanel', 'caption' => 'Messwerte', 'items' => [
                    ['type' => 'SelectVariable', 'name' => 'PVPowerID', 'caption' => 'PV-Leistung aktuell [W]', 'validVariableTypes' => [1, 2]],
                    ['type' => 'SelectVariable', 'name' => 'BrightnessID', 'caption' => 'Außenhelligkeit [Lux]', 'validVariableTypes' => [1, 2]],
                    ['type' => 'SelectVariable', 'name' => 'SunAzimuthID', 'caption' => 'Sonnenazimut [°]', 'validVariableTypes' => [1, 2]],
                    ['type' => 'SelectVariable', 'name' => 'SunElevationID', 'caption' => 'Sonnenhöhe [°]', 'validVariableTypes' => [1, 2]]
                ]],
                ['type' => 'ExpansionPanel', 'caption' => 'PV-Anlage', 'items' => [
                    ['type' => 'NumberSpinner', 'name' => 'PVNominalPowerKWp', 'caption' => 'PV-Nennleistung [kWp]', 'minimum' => 0.1, 'maximum' => 100.0, 'digits' => 2],
                    ['type' => 'NumberSpinner', 'name' => 'PVPerformanceFactor', 'caption' => 'PV-Systemfaktor', 'minimum' => 0.5, 'maximum' => 1.1, 'digits' => 2],
                    ['type' => 'NumberSpinner', 'name' => 'PVAzimuth', 'caption' => 'PV-Azimut [°] (0=N, 90=O, 180=S, 270=W)', 'minimum' => 0, 'maximum' => 359, 'digits' => 1],
                    ['type' => 'NumberSpinner', 'name' => 'PVTilt', 'caption' => 'PV-Neigung [°]', 'minimum' => 0, 'maximum' => 90, 'digits' => 1],
                    ['type' => 'CheckBox', 'name' => 'PVPowerAbsolute', 'caption' => 'Vorzeichen der PV-Leistung ignorieren'],
                    ['type' => 'NumberSpinner', 'name' => 'LuxPerWm2', 'caption' => 'Lux pro W/m² (Startwert 120)', 'minimum' => 50, 'maximum' => 250, 'digits' => 1]
                ]],
                ['type' => 'Label', 'caption' => 'Phase 1: lokale Schätzung ohne Wetterdienst. PV ist das Haupt-Energiesignal; Lux hilft bei diffusem Licht und ungünstiger PV-Geometrie.']
            ],
            'actions' => [
                ['type' => 'Button', 'caption' => 'Jetzt berechnen', 'onClick' => 'SEE_Evaluate($id);']
            ],
            'status' => [
                ['code' => 102, 'icon' => 'active', 'caption' => 'Aktiv'],
                ['code' => 104, 'icon' => 'inactive', 'caption' => 'Inaktiv']
            ]
        ], JSON_UNESCAPED_UNICODE);
    }

    private function facadeRadiation(float $sunAzimuth, float $sunElevation, float $facadeAzimuth, float $dni, float $diffuseHorizontal): float
    {
        if ($sunElevation <= 0.0) {
            return 0.0;
        }
        $cosIncidence = $this->surfaceIncidenceCosine($sunAzimuth, $sunElevation, $facadeAzimuth, 90.0);
        $direct = $dni * max(0.0, $cosIncidence);
        $diffuse = 0.50 * $diffuseHorizontal;
        return min(1300.0, max(0.0, $direct + $diffuse));
    }

    private function surfaceIncidenceCosine(float $sunAzimuth, float $sunElevation, float $surfaceAzimuth, float $surfaceTilt): float
    {
        if ($sunElevation <= -0.5) {
            return 0.0;
        }
        $el = deg2rad($sunElevation);
        $tilt = deg2rad($surfaceTilt);
        $deltaAz = deg2rad($this->signedAngle($sunAzimuth - $surfaceAzimuth));

        $cos = (sin($el) * cos($tilt)) + (cos($el) * sin($tilt) * cos($deltaAz));
        return max(0.0, min(1.0, $cos));
    }

    private function readNumeric(int $id): ?float
    {
        if ($id <= 0 || !IPS_VariableExists($id)) {
            return null;
        }
        try {
            $value = GetValue($id);
            return is_numeric($value) ? (float)$value : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    private function normalizeAngle(float $angle): float
    {
        $angle = fmod($angle, 360.0);
        return $angle < 0.0 ? $angle + 360.0 : $angle;
    }

    private function signedAngle(float $angle): float
    {
        $angle = fmod($angle + 180.0, 360.0);
        if ($angle < 0.0) $angle += 360.0;
        return $angle - 180.0;
    }

    private function clamp01(float $value): float
    {
        return max(0.0, min(1.0, $value));
    }

    private function writeIfChangedFloat(string $ident, float $value, float $epsilon): void
    {
        $id = $this->GetIDForIdent($ident);
        $old = (float)GetValue($id);
        if (abs($old - $value) > $epsilon) {
            SetValueFloat($id, $value);
        }
    }

    private function writeIfChangedString(string $ident, string $value): void
    {
        $id = $this->GetIDForIdent($ident);
        if ((string)GetValue($id) !== $value) {
            SetValueString($id, $value);
        }
    }

    private function writeIfChangedInt(string $ident, int $value): void
    {
        $id = $this->GetIDForIdent($ident);
        if ((int)GetValue($id) !== $value) {
            SetValueInteger($id, $value);
        }
    }
}
