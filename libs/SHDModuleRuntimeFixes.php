<?php
declare(strict_types=1);

trait SHDModuleRuntimeFixes
{
    public function Evaluate(): void
    {
        if(!$this->ReadPropertyBoolean('Active')) return;

        $memory=$this->memory();
        $engine=new SHDEngine();
        $decisions=[];$inventory=[];$events=[];

        foreach($this->blinds() as $key=>$b){
            if(!($b['enabled']??true)) continue;

            $old=$memory[(string)$key]??[];
            $s=$this->state($b,$old);
            $effective=$b;

            if(($s['privacyLevel']??'NONE')==='NONE'){
                $effective['privacyDayPosition']=null;
                $effective['privacyDaySlat']=null;
            }

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
