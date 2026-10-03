<?php
declare(strict_types=1);

require_once __DIR__ . '/../libs/DecisionEngine.php';

$engine = new SHDEngine();
$blind = SHDConfig::normalizePublic([
    'name' => 'West Venetian',
    'type' => 'venetian',
    'facadeAzimuth' => 270,
    'privacyNightPosition' => 100,
    'privacyNightSlat' => 100
]);

$cases = [
    'wind beats everything' => [
        ['windAlarm'=>true,'directSun'=>true,'radiationFiltered'=>700,'roomTemp'=>25,'roomSetpoint'=>21,'sunElevation'=>25],
        'WIND'
    ],
    'overheating' => [
        ['directSun'=>true,'radiationFiltered'=>650,'roomTemp'=>23.5,'roomSetpoint'=>21.5,'outsideTemp'=>28,'sunElevation'=>30],
        'OVERHEATING'
    ],
    'solar heating beats normal shade' => [
        ['directSun'=>true,'radiationFiltered'=>400,'roomTemp'=>20,'roomSetpoint'=>21.5,'outsideTemp'=>5,'sunElevation'=>20],
        'SOLAR_HEATING'
    ],
    'night privacy' => [
        ['isNight'=>true],
        'PRIVACY_NIGHT'
    ]
];

$fail = 0;
foreach ($cases as $name => [$state, $expected]) {
    $got = $engine->decide($blind, $state)->reasonCode;
    $ok = $got === $expected;
    echo ($ok ? '[OK] ' : '[FAIL] ') . $name . ': ' . $got . PHP_EOL;
    if (!$ok) $fail++;
}
exit($fail === 0 ? 0 : 1);
