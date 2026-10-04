<?php
declare(strict_types=1);

trait SHDModuleData
{
    private function configured(): array
    {
        $rows=json_decode($this->ReadPropertyString('Blinds'),true);if(!is_array($rows))return[];$r=[];
        foreach($rows as $i=>$row){if(!is_array($row))continue;$b=SHDConfig::normalizePublic($row);$k=$b['blindID']>0?(string)$b['blindID']:'configured_'.$i;$r[$k]=$b;}
        return$r;
    }

    private function blinds(): array
    {
        $legacy=$this->ReadPropertyBoolean('AutoDiscoverLegacy')?$this->legacy():[];
        $configured=$this->configured();
        if(!$this->ReadPropertyBoolean('AutoDiscoverLegacy'))return$configured;

        foreach($configured as $k=>$cfg){
            if(!isset($legacy[(string)$k])){$legacy[(string)$k]=$cfg;continue;}
            $base=$legacy[(string)$k];$merged=array_replace($base,$cfg);
            foreach(['calendarModeID','scheduleEventID','positionControlID','positionStatusID','slatControlID','slatStatusID','roomTempID','roomSetpointID'] as $field){
                if((int)($cfg[$field]??0)<=0&&(int)($base[$field]??0)>0)$merged[$field]=(int)$base[$field];
            }

            // Older form versions persisted 0 for an unset facade azimuth. For legacy rows this is
            // a UI default, not the actual house orientation. Always restore the mapped legacy azimuth.
            if(($cfg['source']??'')==='legacy'&&array_key_exists('facadeAzimuth',$base)&&$base['facadeAzimuth']!==null){
                $merged['facadeAzimuth']=$base['facadeAzimuth'];
            }elseif((!array_key_exists('facadeAzimuth',$cfg)||$cfg['facadeAzimuth']===null||$cfg['facadeAzimuth']==='')&&array_key_exists('facadeAzimuth',$base)){
                $merged['facadeAzimuth']=$base['facadeAzimuth'];
            }
            foreach(['sunFrom','sunTo'] as $field){
                if((!array_key_exists($field,$cfg)||$cfg[$field]===null||$cfg[$field]==='')&&array_key_exists($field,$base))$merged[$field]=$base[$field];
            }

            $merged['warnings']=array_values(array_filter(array_unique(array_merge($base['warnings']??[],$cfg['warnings']??[])),fn($x)=>!str_contains((string)$x,'ID Lamellensteuerung')));
            $merged['infos']=array_values(array_unique(array_merge($base['infos']??[],$cfg['infos']??[])));
            $legacy[(string)$k]=$merged;
        }
        return$legacy;
    }

    private function legacy(): array
    {
        $r=[];
        foreach(IPS_GetObjectList() as $id){
            $o=IPS_GetObject($id);if(($o['ObjectType']??-1)!==2||($o['ObjectName']??'')!=='Konfiguration')continue;
            $v=IPS_GetVariable($id);if(($v['VariableType']??-1)!==3)continue;
            $raw=json_decode(GetValueString($id),true);if(!is_array($raw)||(!array_key_exists('BehangID',$raw)&&!array_key_exists('Typ',$raw)))continue;
            $b=SHDConfig::normalizeLegacy($raw,$id);$bid=$b['blindID']?:IPS_GetParent($id);if($bid<=0||!IPS_ObjectExists($bid))$bid=IPS_GetParent($id);
            $b['blindID']=$bid;
            if(($b['positionControlID']??0)<=0&&$bid>0&&IPS_InstanceExists($bid))$b['positionControlID']=$bid;
            $b['name']=IPS_GetName($bid);$b['facadeAzimuth']=$this->legacyAz($b['facade']??null);$this->mapLegacy($b);$r[(string)$bid]=$b;
        }
        return$r;
    }

    private function legacyAz(mixed $f): ?float
    {
        if($f===null||$f==='')return null;
        return match((int)$f){0=>$this->ReadPropertyFloat('LegacyFacade0Azimuth'),1=>$this->ReadPropertyFloat('LegacyFacade1Azimuth'),2=>$this->ReadPropertyFloat('LegacyFacade2Azimuth'),3=>$this->ReadPropertyFloat('LegacyFacade3Azimuth'),default=>null};
    }

    private function mapLegacy(array &$b): void
    {
        $bid=(int)($b['blindID']??0);
        if($bid>0&&IPS_ObjectExists($bid)){
            foreach(IPS_GetChildrenIDs($bid) as $c){
                $o=IPS_GetObject($c);$type=(int)($o['ObjectType']??-1);$name=mb_strtolower(trim((string)($o['ObjectName']??'')));
                if($type===2&&IPS_VariableExists($c)&&$name==='aktuelles programm'){
                    $b['calendarModeID']=$c;
                    foreach(IPS_GetChildrenIDs($c) as $eventID){
                        $eo=IPS_GetObject($eventID);$etype=(int)($eo['ObjectType']??-1);$ename=mb_strtolower(trim((string)($eo['ObjectName']??'')));
                        if($etype!==4)continue;
                        $isSchedule=false;
                        if(function_exists('IPS_GetEvent')){
                            try{$ev=IPS_GetEvent($eventID);$isSchedule=((int)($ev['EventType']??-1)===2);}catch(Throwable $e){}
                        }
                        if($isSchedule||str_contains($ename,'wochenplan')||str_contains($ename,'ereignis')){$b['scheduleEventID']=$eventID;break;}
                    }
                }
                if($type===4&&(int)($b['scheduleEventID']??0)<=0){
                    $isSchedule=false;
                    if(function_exists('IPS_GetEvent')){
                        try{$ev=IPS_GetEvent($c);$isSchedule=((int)($ev['EventType']??-1)===2);}catch(Throwable $e){}
                    }
                    if($isSchedule||str_contains($name,'wochenplan')||str_contains($name,'ereignis'))$b['scheduleEventID']=$c;
                }
            }
        }

        $tokens=array_values(array_filter(preg_split('/\s+/u',preg_replace('/[^\pL\pN]+/u',' ',mb_strtolower((string)($b['name']??'')))??''),fn($x)=>mb_strlen($x)>=4&&!in_array($x,['höhe','anfahren'],true)));
        foreach(IPS_GetObjectList() as $id){
            $o=IPS_GetObject($id);if(($o['ObjectType']??-1)!==2)continue;$n=mb_strtolower((string)($o['ObjectName']??''));$match=false;
            foreach($tokens as $t)if(str_contains($n,$t)){$match=true;break;}if(!$match)continue;
            if(($b['positionStatusID']??0)<=0&&(str_contains($n,'position')||str_contains($n,'status'))&&!str_contains($n,'lamell'))$b['positionStatusID']=$id;
            if(($b['slatStatusID']??0)<=0&&str_contains($n,'lamell')&&(str_contains($n,'pos')||str_contains($n,'status')))$b['slatStatusID']=$id;
        }
    }

    private function effectivePositionValueID(array $b): int
    {
        $status=(int)($b['positionStatusID']??0);if($status>0&&IPS_VariableExists($status))return$status;
        $control=(int)($b['positionControlID']??0);if($control>0&&IPS_InstanceExists($control)){
            $valueID=@IPS_GetObjectIDByIdent('Value',$control);if($valueID!==false&&IPS_VariableExists((int)$valueID))return(int)$valueID;
        }
        if($control>0&&IPS_VariableExists($control))return$control;
        return 0;
    }

    private function effectiveSlatValueID(array $b): int
    {
        $status=(int)($b['slatStatusID']??0);if($status>0&&IPS_VariableExists($status))return$status;
        $control=(int)($b['slatControlID']??0);if($control>0&&IPS_InstanceExists($control)){
            $valueID=@IPS_GetObjectIDByIdent('Value',$control);if($valueID!==false&&IPS_VariableExists((int)$valueID))return(int)$valueID;
        }
        if($control>0&&IPS_VariableExists($control))return$control;
        return 0;
    }

    private function objectDisplayName(int $id): string
    {
        if($id<=0||!IPS_ObjectExists($id))return'–';
        return IPS_GetName($id).' ['.$id.']';
    }

    private function feedbackDisplay(array $b): string
    {
        $status=(int)($b['positionStatusID']??0);
        if($status>0&&IPS_VariableExists($status))return'separat: '.$this->objectDisplayName($status);
        if($this->effectivePositionValueID($b)>0&&(int)($b['positionControlID']??0)>0)return'integriert';
        return'fehlt';
    }

    private function validateBlind(array $b): array
    {
        $e=[];$w=$b['warnings']??[];$i=$b['infos']??[];
        if(trim((string)($b['name']??''))==='')$e[]='Name fehlt.';
        if(SHDMath::facadeAzimuth($b)===null&&(($b['sunFrom']??null)===null||($b['sunTo']??null)===null))$w[]='Keine nutzbare Fassadenausrichtung oder Sonnenfenster vorhanden.';
        if((int)($b['roomTempID']??0)<=0)$i[]='Raumtemperatur fehlt; thermische Regeln sind eingeschränkt.';
        if((int)($b['roomSetpointID']??0)<=0)$i[]='Raum-Solltemperatur fehlt; thermische Regeln sind eingeschränkt.';
        if($this->effectivePositionValueID($b)<=0)$w[]='Positionswert/-rückmeldung fehlt.';
        if(($b['type']??'')==='venetian'&&$this->effectiveSlatValueID($b)<=0)$w[]='Lamellenwert/-rückmeldung fehlt.';
        if((int)($b['calendarModeID']??0)<=0)$i[]='Variable „Aktuelles Programm“ fehlt; Standardmodus wird verwendet.';
        if((int)($b['scheduleEventID']??0)<=0)$i[]='Wochenplan/Kalender-Ereignis nicht zugeordnet.';
        return['errors'=>array_values(array_unique($e)),'warnings'=>array_values(array_unique($w)),'infos'=>array_values(array_unique($i))];
    }

    private function inventoryEntry(array $b): array
    {
        return['blindID'=>$b['blindID']??0,'name'=>$b['name']??'','source'=>$b['source']??'module','type'=>$b['type']??'','room'=>$b['room']??'','facadeAzimuth'=>SHDMath::facadeAzimuth($b),'sunFrom'=>$b['sunFrom']??null,'sunTo'=>$b['sunTo']??null,'calendarModeID'=>$b['calendarModeID']??0,'scheduleEventID'=>$b['scheduleEventID']??0,'sleepStateID'=>$b['sleepStateID']??0,'wakeReleaseID'=>$b['wakeReleaseID']??0,'daylightReleaseID'=>$b['daylightReleaseID']??0,'controlLockID'=>$b['controlLockID']??0,'panicID'=>$b['panicID']??0,'positionControlID'=>$b['positionControlID']??0,'positionStatusID'=>$b['positionStatusID']??0,'effectivePositionValueID'=>$this->effectivePositionValueID($b),'slatControlID'=>$b['slatControlID']??0,'slatStatusID'=>$b['slatStatusID']??0,'effectiveSlatValueID'=>$this->effectiveSlatValueID($b),'doorContactID'=>$b['doorContactID']??0,'roomTempID'=>$b['roomTempID']??0,'roomSetpointID'=>$b['roomSetpointID']??0,'validation'=>$this->validateBlind($b)];
    }

    private function fmt(mixed $v,string $s): string{return$v===null?'–':number_format((float)$v,1,',','.').$s;}
    private function num(int $id): ?float{if($id<=0||!IPS_VariableExists($id))return null;$v=GetValue($id);return is_numeric($v)?(float)$v:null;}
    private function intVar(int $id): ?int{if($id<=0||!IPS_VariableExists($id))return null;$v=GetValue($id);return is_numeric($v)?(int)$v:null;}
    private function boolVar(int $id): ?bool{return($id<=0||!IPS_VariableExists($id))?null:(bool)GetValue($id);}
}
