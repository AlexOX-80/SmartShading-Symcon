<?php
declare(strict_types=1);

trait SHDModuleProtocolTolerance
{
    private function shouldLog(array $e,array $m): bool
    {
        $d=$e['decision'];$s=$e['state'];
        $changed=($m['lastReasonCode']??null)!==($d['reasonCode']??null)
            ||!$this->sameNullableNumber($m['lastTargetPosition']??null,$d['position']??null)
            ||!$this->sameNullableNumber($m['lastTargetSlat']??null,$d['slat']??null,2.0)
            ||($m['lastCalendarMode']??null)!==($s['calendarMode']??null)
            ||($m['lastPrivacyLevel']??null)!==($s['privacyLevel']??null)
            ||($m['lastSleepActive']??null)!==($s['sleepActive']??null)
            ||($m['lastWakeRelease']??null)!==($s['wakeRelease']??null)
            ||($m['lastControlLockDesired']??null)!==($d['controlLockDesired']??null)
            ||($m['lastDirectSun']??null)!==($s['directSun']??null);

        $oldRad=$m['lastRadiation']??null;
        $newRad=$s['radiationFiltered']??null;
        if(!$this->sameNullableNumber($oldRad,$newRad,99.9))$changed=true;

        if($changed)return true;
        return time()-(int)($m['lastProtocolTimestamp']??0)>=max(15,$this->ReadPropertyInteger('ProtocolSnapshotMinutes'))*60;
    }
}
