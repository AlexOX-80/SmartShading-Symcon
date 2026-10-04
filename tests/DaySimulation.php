<?php
declare(strict_types=1);
require_once __DIR__ . '/../libs/DecisionEngine.php';

$engine = new SHDEngine();
$blind = SHDConfig::normalizePublic([
    'name' => 'Bedroom demo',
    'type' => 'roller',
    'privacyDayPosition' => 20.0,
    'privacyNightPosition' => 60.0,
    'sleepPosition' => 100.0,
    'coldNightPosition' => 90.0
]);

$timeline = [
    ['05:30', ['sleepActive'=>true, 'wakeRelease'=>false, 'isDark'=>true, 'privacyLevel'=>'NIGHT', 'daylightAvailable'=>false, 'daylightReleased'=>false, 'outsideTemp'=>2]],
    ['06:45', ['sleepActive'=>true, 'wakeRelease'=>false, 'isDark'=>false, 'privacyLevel'=>'DAY', 'daylightAvailable'=>true, 'daylightReleased'=>false, 'outsideTemp'=>3]],
    ['07:15', ['sleepActive'=>true, 'wakeRelease'=>true, 'isDark'=>false, 'privacyLevel'=>'DAY', 'daylightAvailable'=>true, 'daylightReleased'=>true, 'outsideTemp'=>4]],
    ['12:30', ['sleepActive'=>false, 'wakeRelease'=>true, 'isDark'=>false, 'privacyLevel'=>'DAY', 'daylightAvailable'=>true, 'daylightReleased'=>true, 'outsideTemp'=>15, 'directSun'=>true, 'radiationFiltered'=>550, 'roomTemp'=>22.5, 'roomSetpoint'=>21]],
    ['18:45', ['sleepActive'=>false, 'wakeRelease'=>true, 'isDark'=>true, 'privacyLevel'=>'NIGHT', 'daylightAvailable'=>false, 'daylightReleased'=>true, 'outsideTemp'=>8]],
    ['23:00', ['sleepActive'=>true, 'wakeRelease'=>false, 'isDark'=>true, 'privacyLevel'=>'NIGHT', 'daylightAvailable'=>false, 'daylightReleased'=>false, 'outsideTemp'=>4]],
];

foreach ($timeline as [$time, $patch]) {
    $state = array_merge([
        'calendarMode'=>SHDMode::AUTO,
        'windAlarm'=>false, 'hailAlarm'=>false, 'emergency'=>false, 'doorOpen'=>false, 'panic'=>false,
        'sleepActive'=>false, 'wakeRelease'=>true, 'privacyLevel'=>'DAY', 'isDark'=>false,
        'coldNightActive'=>false, 'directSun'=>false, 'daylightAvailable'=>true, 'daylightReleased'=>true,
        'roomTemp'=>21.0, 'roomSetpoint'=>21.0, 'roomTempTrendKPerH'=>0.0,
        'outsideTemp'=>10.0, 'radiationFiltered'=>0.0,
        'sunOnThreshold'=>300.0, 'sunOffThreshold'=>180.0,
        'actualPosition'=>50.0, 'actualSlat'=>null
    ], $patch);
    $d = $engine->decide($blind, $state);
    printf("%s  %-18s target=%5s%% lock=%s  %s\n",
        $time, $d->reasonCode,
        $d->position === null ? '-' : number_format($d->position, 0),
        $d->controlLockDesired ? 'yes' : 'no',
        $d->reason
    );
}
