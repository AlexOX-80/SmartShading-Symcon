<?php
declare(strict_types=1);

require_once __DIR__ . '/../libs/DecisionEngine.php';
require_once __DIR__ . '/../libs/SHDModuleUI.php';
require_once __DIR__ . '/../libs/SHDModuleRuntime.php';
require_once __DIR__ . '/../libs/SHDModuleData.php';

class SmartShading extends IPSModule
{
    use SHDModuleUI, SHDModuleRuntime, SHDModuleData;
}
