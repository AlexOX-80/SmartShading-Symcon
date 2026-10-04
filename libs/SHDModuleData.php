<?php
declare(strict_types=1);

trait SHDModuleData
{

    private function configured(): array
        {
            $rows=json_decode($this->ReadPropertyString('Blinds'),true);if(!is_array($rows))return[];$r=[];
            foreach($rows as $i=>$row){if(!is_array($row))continue;$b=SHDConfig::normalizePublic($row);$k=$b['blindID']>0?(string)$b['blindID']:'configured_'.$i;$r[$k]=$b;}return$r;
        }

    private function blinds(): array { $c=$this->configured();if(!$this->ReadPropertyBoolean('AutoDiscoverLegacy'))return$c;foreach($this->legacy() as $k=>$b)if(!isset($c[(string)$k]))$c[(string)$k]=$b;return$c; }

    private function legacy(): array
        {
            $r=[];foreach(IPS_GetObjectList() as $id){$o=IPS_GetObject($id);if(($o['ObjectType']??-1)!==2||($o['ObjectName']??'')!=='Konfiguration')continue;$v=IPS_GetVariable($id);if(($v['VariableType']??-1)!==3)continue;$raw=json_decode(GetValueString($id),true);if(!is_array($raw)||(!array_key_exists('BehangID',$raw)&&!array_key_exists('Typ',$raw)))continue;
                $b=SHDConfig::normalizeLegacy($raw,$id);$bid=$b['blindID']?:IPS_GetParent($id);if($bid<=0||!IPS_ObjectExists($bid))$bid=IPS_GetParent($id);$b['blindID']=$bid;$b['name']=IPS_GetName($bid);$b['facadeAzimuth']=$this->legacyAz($b['facade']??null);$this->mapLegacy($b);$r[(string)$bid]=$b;}
            return$r;
        }

    private function legacyAz(mixed $f): ?float { if($f===null||$f==='')return null;return match((int)$f){0=>$this->ReadPropertyFloat('LegacyFacade0Azimuth'),1=>$this->ReadPropertyFloat('LegacyFacade1Azimuth'),2=>$this->ReadPropertyFloat('LegacyFacade2Azimuth'),3=>$this->ReadPropertyFloat('LegacyFacade3Azimuth'),default=>null}; }

    private function mapLegacy(array &$b): void
        {
            $bid=(int)($b['blindID']??0);if($bid>0&&IPS_ObjectExists($bid))foreach(IPS_GetChildrenIDs($bid) as $c){if(!IPS_VariableExists($c))continue;$v=IPS_GetVariable($c);$p=(string)($v['VariableCustomProfile']??$v['VariableProfile']??'');if(mb_strtolower(IPS_GetName($c))==='aktuelles programm'&&$p==='AutomatikProgrammBehang')$b['calendarModeID']=$c;}
            $tokens=array_values(array_filter(preg_split('/\s+/u',preg_replace('/[^\pL\pN]+/u',' ',mb_strtolower((string)($b['name']??'')))??''),fn($x)=>mb_strlen($x)>=4&&!in_array($x,['höhe','anfahren'],true)));
            foreach(IPS_GetObjectList() as $id){$o=IPS_GetObject($id);if(($o['ObjectType']??-1)!==2)continue;$n=mb_strtolower((string)($o['ObjectName']??''));$match=false;foreach($tokens as $t)if(str_contains($n,$t)){$match=true;break;}if(!$match)continue;if(($b['positionStatusID']??0)<=0&&(str_contains($n,'position')||str_contains($n,'status'))&&!str_contains($n,'lamell'))$b['positionStatusID']=$id;if(($b['slatStatusID']??0)<=0&&str_contains($n,'lamell')&&(str_contains($n,'pos')||str_contains($n,'status')))$b['slatStatusID']=$id;}
        }

    private function validateBlind(array $b): array
        {
            $e=[];$w=$b['warnings']??[];$i=$b['infos']??[];if(trim((string)($b['name']??''))==='')$e[]='Name is empty.';if(SHDMath::facadeAzimuth($b)===null&&(($b['sunFrom']??null)===null||($b['sunTo']??null)===null))$w[]='No usable facade azimuth or sun window.';if((int)($b['roomTempID']??0)<=0)$i[]='Room temperature missing; thermal rules reduced.';if((int)($b['roomSetpointID']??0)<=0)$i[]='Room setpoint missing; thermal rules reduced.';if((int)($b['positionStatusID']??0)<=0)$w[]='Position feedback missing.';if(($b['type']??'')==='venetian'&&(int)($b['slatStatusID']??0)<=0)$w[]='Slat feedback missing.';if((int)($b['calendarModeID']??0)<=0)$i[]='No calendar mode variable mapped; default mode used.';return['errors'=>array_values(array_unique($e)),'warnings'=>array_values(array_unique($w)),'infos'=>array_values(array_unique($i))];
        }

    private function inventoryEntry(array $b): array { return['blindID'=>$b['blindID']??0,'name'=>$b['name']??'','source'=>$b['source']??'module','type'=>$b['type']??'','room'=>$b['room']??'','facadeAzimuth'=>SHDMath::facadeAzimuth($b),'sunFrom'=>$b['sunFrom']??null,'sunTo'=>$b['sunTo']??null,'calendarModeID'=>$b['calendarModeID']??0,'sleepStateID'=>$b['sleepStateID']??0,'wakeReleaseID'=>$b['wakeReleaseID']??0,'daylightReleaseID'=>$b['daylightReleaseID']??0,'controlLockID'=>$b['controlLockID']??0,'panicID'=>$b['panicID']??0,'positionControlID'=>$b['positionControlID']??0,'positionStatusID'=>$b['positionStatusID']??0,'slatControlID'=>$b['slatControlID']??0,'slatStatusID'=>$b['slatStatusID']??0,'doorContactID'=>$b['doorContactID']??0,'roomTempID'=>$b['roomTempID']??0,'roomSetpointID'=>$b['roomSetpointID']??0,'validation'=>$this->validateBlind($b)]; }

    private function fmt(mixed $v,string $s): string { return $v===null?'–':number_format((float)$v,1,',','.').$s; }

    private function num(int $id): ?float { if($id<=0||!IPS_VariableExists($id))return null;$v=GetValue($id);return is_numeric($v)?(float)$v:null; }

    private function intVar(int $id): ?int { if($id<=0||!IPS_VariableExists($id))return null;$v=GetValue($id);return is_numeric($v)?(int)$v:null; }

    private function boolVar(int $id): ?bool { return($id<=0||!IPS_VariableExists($id))?null:(bool)GetValue($id); }

}
