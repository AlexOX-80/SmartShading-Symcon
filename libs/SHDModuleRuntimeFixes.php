<?php
declare(strict_types=1);

trait SHDModuleRuntimeFixes
{
    public function Evaluate(): void
    {
        if(!$this->ReadPropertyBoolean('Active')) return;

        // Register here as well so existing module instances receive the new visualization
        // without requiring recreation of the instance.
        $this->RegisterVariableString('SunMap','Sonnenkarte','~HTMLBox');

        $memory=$this->memory();
        $engine=new SHDEngine();
        $decisions=[];$inventory=[];$events=[];

        foreach($this->blinds() as $key=>$b){
            if(!($b['enabled']??true)) continue;

            $old=$memory[(string)$key]??[];
            $s=$this->state($b,$old);
            $effective=$b;

            // Bright daytime without a real privacy requirement must not inherit
            // old PRIVACY_DAY minimum positions from the legacy configuration.
            if(($s['privacyLevel']??'NONE')==='NONE'){
                $effective['privacyDayPosition']=null;
                $effective['privacyDaySlat']=null;
            }

            // Astronomical incidence alone is not enough for thermal shading.
            // At very low/zero measured radiation there is no usable solar load.
            $rad=$s['radiationFiltered']??null;
            $minSolar=max(150.0,$this->ReadPropertyFloat('SunOffThreshold'));
            if($rad!==null&&(float)$rad<$minSolar){
                $s['directSunForThermal']=false;
                $s['directSun']=false;
            }else{
                $s['directSunForThermal']=$s['directSun']??false;
            }

            $d=$engine->decide($effective,$s,$old);
            $entry=['name'=>$b['name']??('Blind '.$key),'decision'=>$d->toArray(),'state'=>$s,'validation'=>$this->validateBlind($b),'shadowMode'=>true];
            $decisions[(string)$key]=$entry;
            $inventory[(string)$key]=$this->inventoryEntry($b);

            $log=$this->shouldLog($entry,$old);
            if($log)$events[]=$this->protocolEvent((string)$key,$entry);

            $memory[(string)$key]=[
                'lastReasonCode'=>$d->reasonCode,
                'baseReasonCode'=>$d->reasonCode,
                'lastPriority'=>$d->priority,
                'lastTargetPosition'=>$d->position,
                'lastTargetSlat'=>$d->slat,
                'lastCalendarMode'=>$s['calendarMode'],
                'lastPrivacyLevel'=>$s['privacyLevel'],
                'lastSleepActive'=>$s['sleepActive'],
                'lastWakeRelease'=>$s['wakeRelease'],
                'lastControlLockDesired'=>$d->controlLockDesired,
                'lastDirectSun'=>$s['directSun']??false,
                'lastRadiation'=>$s['radiationFiltered']??null,
                'lastProtocolTimestamp'=>$log?time():(int)($old['lastProtocolTimestamp']??0),
                'coldNightActive'=>$s['coldNightActive'],
                'timestamp'=>time()
            ];
        }

        $this->WriteAttributeString('DecisionMemory',json_encode($memory,JSON_UNESCAPED_UNICODE));
        if($events)$this->appendProtocol($events);
        SetValueString($this->GetIDForIdent('StatusSummary'),json_encode(['timestamp'=>time(),'count'=>count($decisions),'shadowMode'=>true,'decisions'=>$decisions],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
        SetValueString($this->GetIDForIdent('Inventory'),json_encode($inventory,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
        SetValueString($this->GetIDForIdent('Dashboard'),$this->dashboard($decisions));
        SetValueString($this->GetIDForIdent('DayProtocol'),$this->protocolHtml(date('Y-m-d')));
        SetValueString($this->GetIDForIdent('SunMap'),$this->sunMapHtml($decisions));
        SetValueInteger($this->GetIDForIdent('LastEvaluation'),time());
    }
}
