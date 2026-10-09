<?php
declare(strict_types=1);

require_once __DIR__ . '/../libs/DecisionEngine.php';
require_once __DIR__ . '/../libs/SHDModuleUI.php';
require_once __DIR__ . '/../libs/SHDModuleProtocolDownload.php';
require_once __DIR__ . '/../libs/SHDModuleKnxObserver.php';
require_once __DIR__ . '/../libs/SHDModuleRuntime.php';
require_once __DIR__ . '/../libs/SHDModuleRuntimeFixes.php';
require_once __DIR__ . '/../libs/SHDModuleSunMap.php';
require_once __DIR__ . '/../libs/SHDModuleSetpoint.php';
require_once __DIR__ . '/../libs/SHDModuleProtocolTolerance.php';
require_once __DIR__ . '/../libs/SHDModuleShadowSun.php';
require_once __DIR__ . '/../libs/SHDModuleData.php';

class SmartShading extends IPSModule
{
    use SHDModuleUI, SHDModuleProtocolDownload, SHDModuleKnxObserver, SHDModuleRuntime, SHDModuleRuntimeFixes, SHDModuleSunMap, SHDModuleSetpoint, SHDModuleProtocolTolerance, SHDModuleShadowSun, SHDModuleData {
        SHDModuleUI::Create as private CreateBase;
        SHDModuleKnxObserver::Create insteadof SHDModuleUI;
        SHDModuleUI::ApplyChanges as private ApplyChangesBase;
        SHDModuleKnxObserver::ApplyChanges insteadof SHDModuleUI;

        SHDModuleUI::GetConfigurationForm as private GetConfigurationFormBase;
        SHDModuleProtocolDownload::GetConfigurationForm insteadof SHDModuleUI;
        SHDModuleProtocolDownload::GetConfigurationForm as private GetConfigurationFormObserverBase;
        SHDModuleKnxObserver::GetConfigurationForm insteadof SHDModuleUI, SHDModuleProtocolDownload;

        SHDModuleRuntime::GetDailyProtocolJSON as private GetDailyProtocolJSONBase;
        SHDModuleKnxObserver::GetDailyProtocolJSON insteadof SHDModuleRuntime;

        SHDModuleRuntimeFixes::Evaluate insteadof SHDModuleRuntime;
        SHDModuleSetpoint::effectiveRoomSetpoint insteadof SHDModuleData;
        SHDModuleProtocolTolerance::shouldLog insteadof SHDModuleRuntime;
        SHDModuleRuntime::state as private stateBase;
        SHDModuleShadowSun::state insteadof SHDModuleRuntime;
        SHDModuleRuntime::protocolEvent as private protocolEventBase;
        SHDModuleShadowSun::protocolEvent insteadof SHDModuleRuntime;
    }
}
