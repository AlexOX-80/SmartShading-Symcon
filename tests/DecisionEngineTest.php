<?php
declare(strict_types=1);
require_once __DIR__ . '/../libs/DecisionEngine.php';

function assertEq(string $name, mixed $actual, mixed $expected): void {
    if ($actual !== $expected) {
        fwrite(STDERR, "[FAIL] $name: expected " . var_export($expected, true) . ", got " . var_export($actual, true) . PHP_EOL);
        exit(1);
    }
    echo "[OK] $name" . PHP_EOL;
}

$engine = new SHDEngine();
$baseBlind = SHDConfig::normalizePublic([
    'type' => 'roller',
    'privacyDayPosition' => 20.0,
    'privacyNightPosition' => 60.0,
    'sleepPosition' => 100.0,
    'coldNightPosition' => 90.0
]);
$baseState = [
    'calendarMode' => SHDMode::AUTO,
    'windAlarm' => false, 'hailAlarm' => false, 'emergency' => false,
    'doorOpen' => false, 'panic' => false,
    'sleepActive' => false, 'wakeRelease' => true,
    'privacyLevel' => 'DAY', 'isDark' => false,
    'coldNightActive' => false,
    'directSun' => false, 'daylightAvailable' => true, 'daylightReleased' => true,
    'roomTemp' => 21.0, 'roomSetpoint' => 21.0,
    'roomTempTrendKPerH' => 0.0, 'outsideTemp' => 15.0,
    'radiationFiltered' => 0.0,
    'sunOnThreshold' => 300.0, 'sunOffThreshold' => 180.0,
    'actualPosition' => 50.0, 'actualSlat' => null
];

$d = $engine->decide($baseBlind, $baseState);
assertEq('daylight respects day privacy minimum', $d->position, 20.0);

$s = $baseState;
$s['privacyLevel'] = 'NIGHT';
$s['isDark'] = true;
$s['daylightAvailable'] = false;
$d = $engine->decide($baseBlind, $s);
assertEq('night privacy minimum', $d->position, 60.0);

$s = $baseState;
$s['sleepActive'] = true;
$s['wakeRelease'] = false;
$d = $engine->decide($baseBlind, $s);
assertEq('sleep closes fully', $d->position, 100.0);
assertEq('sleep requests lock', $d->controlLockDesired, true);

$s['wakeRelease'] = true;
$d = $engine->decide($baseBlind, $s);
assertEq('wake release allows daylight despite sleep flag', $d->position, 20.0);
assertEq('wake release removes lock', $d->controlLockDesired, false);

$s = $baseState;
$s['privacyLevel'] = 'NIGHT';
$s['isDark'] = true;
$s['coldNightActive'] = true;
$s['daylightAvailable'] = false;
$d = $engine->decide($baseBlind, $s);
assertEq('cold night closes beyond privacy', $d->position, 90.0);

$s = $baseState;
$s['directSun'] = true;
$s['radiationFiltered'] = 500.0;
$s['roomTemp'] = 19.0;
$s['roomSetpoint'] = 21.0;
$d = $engine->decide($baseBlind, $s);
assertEq('solar heat cannot open beyond day privacy', $d->position, 20.0);

$s = $baseState;
$s['panic'] = true;
$d = $engine->decide($baseBlind, $s);
assertEq('panic opens', $d->position, 0.0);
assertEq('panic requests alert', $d->alertRequested, true);

$s = $baseState;
$s['windAlarm'] = true;
$d = $engine->decide($baseBlind, $s);
assertEq('wind safety wins', $d->reasonCode, 'WIND_SAFETY');

$s = $baseState;
$s['calendarMode'] = SHDMode::FORCE_CLOSED;
$d = $engine->decide($baseBlind, $s);
assertEq('calendar closed', $d->position, 100.0);

echo "All tests passed." . PHP_EOL;
