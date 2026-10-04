<?php
declare(strict_types=1);

require_once __DIR__ . '/../libs/DecisionEngine.php';
require_once __DIR__ . '/../libs/SHDModuleUI.php';
require_once __DIR__ . '/../libs/SHDModuleRuntime.php';
require_once __DIR__ . '/../libs/SHDModuleRuntimeFixes.php';
require_once __DIR__ . '/../libs/SHDModuleSunMap.php';
require_once __DIR__ . '/../libs/SHDModuleSetpoint.php';
require_once __DIR__ . '/../libs/SHDModuleProtocolTolerance.php';
require_once __DIR__ . '/../libs/SHDModuleData.php';

class SmartShading extends IPSModule
{
    use SHDModuleUI, SHDModuleRuntime, SHDModuleRuntimeFixes, SHDModuleSunMap, SHDModuleSetpoint, SHDModuleProtocolTolerance, SHDModuleData {
        SHDModuleRuntimeFixes::Evaluate insteadof SHDModuleRuntime;
        SHDModuleSetpoint::effectiveRoomSetpoint insteadof SHDModuleData;
        SHDModuleProtocolTolerance::shouldLog insteadof SHDModuleRuntime;
    }
}
