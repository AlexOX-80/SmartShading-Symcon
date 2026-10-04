<?php
declare(strict_types=1);

trait SHDModuleRuntime
{
    public function Evaluate(): void
    {
        if(!$this->ReadPropertyBoolean('Active')) return;
        $memory=$this->memory();$engine=new SHDEngine();$decisions=[];$inventory=[];$events=[];
        foreach($this->blinds() as $key=>$b){
            if(!($b['enabled']??true))continue;
            $old=$memory[(string)$key]??[];$s=$this->state($b,$old);$d=$engine->decide($b,$s,$old);
            $entry=['name'=>$b['name']??('Behang '.$key),'decision'=>$d->toArray(),'state'=>$s,'validation'=>$this->validateBlind($b),'shadowMode'=>true];
            $decisions[(string)$key]=$entry;$inventory[(string)$key]=$this->inventoryEntry($b);
            $log=$this->shouldLog($entry,$old);if($log)$events[]=$this->protocolEvent((string)$key,$entry);
            $memory[(string)$key]=[
                'lastReasonCode'=>$d->reasonCode,'baseReasonCode'=>$d->reasonCode,'lastPriority'=>$d->priority,
                'lastTargetPosition'=>$d->position,'lastTargetSlat'=>$d->slat,'lastCalendarMode'=>$s['calendarMode'],
                'lastPrivacyLevel'=>$s['privacyLevel'],'lastSleepActive'=>$s['sleepActive'],'lastWakeRelease'=>$s['wakeRelease'],
                'lastControlLockDesired'=>$d->controlLockDesired,'lastDirectSun'=>$s['directSun'],'lastRadiation'=>$s['radiationFiltered'],
                'lastProtocolTimestamp'=>$log?time():(int)($old['lastProtocolTimestamp']??0),'coldNightActive'=>$s['coldNightActive'],'timestamp'=>time()
            ];
        }
        $this->WriteAttributeString('DecisionMemory',json_encode($memory,JSON_UNESCAPED_UNICODE));
        if($events)$this->appendProtocol($events);
        SetValueString($this->GetIDForIdent('StatusSummary'),json_encode(['timestamp'=>time(),'count'=>count($decisions),'shadowMode'=>true,'decisions'=>$decisions],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
        SetValueString($this->GetIDForIdent('Inventory'),json_encode($inventory,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
        SetValueString($this->GetIDForIdent('Dashboard'),$this->dashboard($decisions));
        SetValueString($this->GetIDForIdent('DayProtocol'),$this->protocolHtml(date('Y-m-d')));
        SetValueInteger($this->GetIDForIdent('LastEvaluation'),time());
    }

    public function GetInventoryJSON(): string{$r=[];foreach($this->blinds() as $k=>$b)$r[(string)$k]=$this->inventoryEntry($b);return json_encode($r,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);}
    public function GetDailyProtocolJSON(string $date=''): string{if($date==='')$date=date('Y-m-d');$e=array_values(array_filter($this->protocol(),fn($x)=>($x['date']??'')===$date));return json_encode(['schema'=>'SmartShadingDayProtocol/1','date'=>$date,'generatedAt'=>date(DATE_ATOM),'eventCount'=>count($e),'events'=>$e],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);}
    public function ClearProtocol(): void{$this->WriteAttributeString('ProtocolLog','[]');SetValueString($this->GetIDForIdent('DayProtocol'),$this->protocolHtml(date('Y-m-d')));}

    public function ValidateConfiguration(): string
    {
        $errors=[];$warnings=[];$infos=[];
        foreach(['SunAzimuthID'=>'Sonnenazimut','SunElevationID'=>'Sonnenhöhe','OutsideTemperatureID'=>'Außentemperatur'] as $p=>$n)if($this->ReadPropertyInteger($p)<=0)$warnings[]="$n ist nicht konfiguriert.";
        if($this->ReadPropertyInteger('RadiationID')<=0)$infos[]='Solarstrahlung ist nicht konfiguriert; Solarregeln sind eingeschränkt.';
        if($this->ReadPropertyInteger('OutdoorBrightnessID')<=0)$infos[]='Außenhelligkeit ist nicht konfiguriert; Tageslicht/Sichtschutz nutzen den Sonnenstand als Ersatz.';
        $ebus=$this->eBusRoomSetpointID();if($ebus>0)$infos[]='eBUS-Raumtemperatur-Sollwert wird als Fallback verwendet: '.IPS_GetName($ebus).' (#'.$ebus.').';else $infos[]='Kein plausibler eBUS-Raumtemperatur-Sollwert als Fallback gefunden.';
        foreach($this->blinds() as $k=>$b){$v=$this->validateBlind($b);$n=$b['name']??$k;foreach($v['errors'] as $x)$errors[]="$n: $x";foreach($v['warnings'] as $x)$warnings[]="$n: $x";foreach($v['infos'] as $x)$infos[]="$n: $x";}
        return json_encode(['blindCount'=>count($this->blinds()),'errorCount'=>count($errors),'warningCount'=>count($warnings),'infoCount'=>count($infos),'errors'=>$errors,'warnings'=>$warnings,'infos'=>$infos],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);
    }

    private function state(array $b,array $mem): array
    {
        $az=$this->num($this->ReadPropertyInteger('SunAzimuthID'));$el=$this->num($this->ReadPropertyInteger('SunElevationID'));$rad=$this->num($this->ReadPropertyInteger('RadiationID'));$out=$this->num($this->ReadPropertyInteger('OutsideTemperatureID'));$olux=$this->num($this->ReadPropertyInteger('OutdoorBrightnessID'));$ilux=$this->num((int)($b['indoorBrightnessID']??0));$faz=SHDMath::facadeAzimuth($b);$rel=($az!==null&&$faz!==null)?SHDMath::signedAngle($az-$faz):0.0;
        $dark=$el!==null?$el<-3.0:((int)date('G')<6||(int)date('G')>=21);$daylight=$olux!==null?$olux>=$this->ReadPropertyFloat('DaylightLuxThreshold'):($el!==null?$el>-3.0:!$dark);
        $privacy='NONE';if($olux!==null&&$ilux!==null)$privacy=($ilux/max(1.0,$olux))>=$this->ReadPropertyFloat('PrivacyRatioThreshold')?'NIGHT':'NONE';elseif($dark)$privacy='NIGHT';
        $raw=$this->intVar((int)($b['calendarModeID']??0));$mode=$raw!==null?SHDMode::fromLegacy($raw):(string)($b['defaultMode']??SHDMode::AUTO);
        $sleep=$this->boolVar((int)($b['sleepStateID']??0))??false;if($mode===SHDMode::SLEEP)$sleep=true;$wake=$this->boolVar((int)($b['wakeReleaseID']??0));if($wake===null)$wake=!$sleep;$release=$this->boolVar((int)($b['daylightReleaseID']??0));if($release===null)$release=$wake;
        $cold=(bool)($mem['coldNightActive']??false);if($out!==null){if(!$cold&&$out<=$this->ReadPropertyFloat('ColdNightOn'))$cold=true;if($cold&&$out>=$this->ReadPropertyFloat('ColdNightOff'))$cold=false;}
        $positionFeedbackID=$this->effectivePositionValueID($b);$slatFeedbackID=$this->effectiveSlatValueID($b);$setpoint=$this->effectiveRoomSetpoint($b);
        return[
            'timestamp'=>time(),'calendarRaw'=>$raw,'calendarMode'=>$mode,'sunAzimuth'=>$az,'sunElevation'=>$el,'relativeSunAzimuth'=>$rel,
            'directSun'=>SHDMath::directSun($b,$az,$el),'radiation'=>$rad,'radiationFiltered'=>$rad,'outsideTemp'=>$out,'outdoorBrightness'=>$olux,'indoorBrightness'=>$ilux,
            'privacyLevel'=>$privacy,'isDark'=>$dark,'daylightAvailable'=>$daylight,'daylightReleased'=>$release,'sleepActive'=>$sleep,'wakeRelease'=>$wake,'coldNightActive'=>$cold,
            'roomTemp'=>$this->num((int)($b['roomTempID']??0)),'roomSetpoint'=>$setpoint['value'],'roomSetpointSource'=>$setpoint['source'],'roomSetpointID'=>$setpoint['id'],'roomTempTrendKPerH'=>0.0,
            'windAlarm'=>$this->boolVar($this->ReadPropertyInteger('WindAlarmID'))??false,'hailAlarm'=>$this->boolVar($this->ReadPropertyInteger('HailAlarmID'))??false,'rainAlarm'=>$this->boolVar($this->ReadPropertyInteger('RainAlarmID'))??false,
            'emergency'=>$this->boolVar($this->ReadPropertyInteger('EmergencyID'))??false,'panic'=>$this->boolVar((int)($b['panicID']??0))??false,'doorOpen'=>$this->boolVar((int)($b['doorContactID']??0))??false,
            'actualPosition'=>$this->num($positionFeedbackID),'actualSlat'=>$this->num($slatFeedbackID),'manualOverride'=>null,'sunOnThreshold'=>$this->ReadPropertyFloat('SunOnThreshold'),'sunOffThreshold'=>$this->ReadPropertyFloat('SunOffThreshold')
        ];
    }

    private function shouldLog(array $e,array $m): bool
    {
        $d=$e['decision'];$s=$e['state'];
        $changed=($m['lastReasonCode']??null)!==($d['reasonCode']??null)
            ||!$this->sameNullableNumber($m['lastTargetPosition']??null,$d['position']??null)
            ||!$this->sameNullableNumber($m['lastTargetSlat']??null,$d['slat']??null)
            ||($m['lastCalendarMode']??null)!==($s['calendarMode']??null)
            ||($m['lastPrivacyLevel']??null)!==($s['privacyLevel']??null)
            ||($m['lastSleepActive']??null)!==($s['sleepActive']??null)
            ||($m['lastWakeRelease']??null)!==($s['wakeRelease']??null)
            ||($m['lastControlLockDesired']??null)!==($d['controlLockDesired']??null)
            ||($m['lastDirectSun']??null)!==($s['directSun']??null);
        $oldRad=$m['lastRadiation']??null;$newRad=$s['radiationFiltered']??null;if(!$this->sameNullableNumber($oldRad,$newRad,99.9))$changed=true;
        if($changed)return true;return time()-(int)($m['lastProtocolTimestamp']??0)>=max(15,$this->ReadPropertyInteger('ProtocolSnapshotMinutes'))*60;
    }

    private function sameNullableNumber(mixed $a,mixed $b,float $epsilon=0.01): bool
    {
        if($a===null||$b===null)return$a===null&&$b===null;if(!is_numeric($a)||!is_numeric($b))return$a===$b;return abs((float)$a-(float)$b)<=$epsilon;
    }

    private function protocolEvent(string $k,array $e): array
    {
        $d=$e['decision'];$s=$e['state'];return[
            'ts'=>time(),'date'=>date('Y-m-d'),'time'=>date('H:i:s'),'blindKey'=>$k,'blind'=>$e['name'],'calendarMode'=>$s['calendarMode']??null,'calendarRaw'=>$s['calendarRaw']??null,
            'reasonCode'=>$d['reasonCode']??null,'reason'=>$d['reason']??null,'priority'=>$d['priority']??null,'targetPosition'=>$d['position']??null,'targetSlat'=>$d['slat']??null,'actualPosition'=>$s['actualPosition']??null,'actualSlat'=>$s['actualSlat']??null,
            'controlLockDesired'=>$d['controlLockDesired']??false,'alertRequested'=>$d['alertRequested']??false,'constraints'=>$d['constraints']??[],'candidates'=>$d['candidates']??[],
            'context'=>['sleepActive'=>$s['sleepActive']??false,'wakeRelease'=>$s['wakeRelease']??true,'daylightAvailable'=>$s['daylightAvailable']??false,'daylightReleased'=>$s['daylightReleased']??true,'privacyLevel'=>$s['privacyLevel']??null,'isDark'=>$s['isDark']??false,
                'directSun'=>$s['directSun']??false,'sunAzimuth'=>$s['sunAzimuth']??null,'sunElevation'=>$s['sunElevation']??null,'radiation'=>$s['radiationFiltered']??null,'outsideTemp'=>$s['outsideTemp']??null,'outdoorBrightness'=>$s['outdoorBrightness']??null,'indoorBrightness'=>$s['indoorBrightness']??null,
                'roomTemp'=>$s['roomTemp']??null,'roomSetpoint'=>$s['roomSetpoint']??null,'roomSetpointSource'=>$s['roomSetpointSource']??'none','roomSetpointID'=>$s['roomSetpointID']??0,'coldNightActive'=>$s['coldNightActive']??false,
                'windAlarm'=>$s['windAlarm']??false,'hailAlarm'=>$s['hailAlarm']??false,'doorOpen'=>$s['doorOpen']??false,'emergency'=>$s['emergency']??false,'panic'=>$s['panic']??false]
        ];
    }

    private function appendProtocol(array $new): void{$all=$this->protocol();foreach($new as $x)$all[]=$x;$cut=time()-max(1,$this->ReadPropertyInteger('ProtocolRetentionDays'))*86400;$all=array_values(array_filter($all,fn($e)=>(int)($e['ts']??0)>=$cut));if(count($all)>20000)$all=array_slice($all,-20000);$this->WriteAttributeString('ProtocolLog',json_encode($all,JSON_UNESCAPED_UNICODE));}
    private function protocol(): array{$x=json_decode($this->ReadAttributeString('ProtocolLog'),true);return is_array($x)?$x:[];}
    private function memory(): array{$x=json_decode($this->ReadAttributeString('DecisionMemory'),true);return is_array($x)?$x:[];}

    private function dashboard(array $ds): string
    {
        $r='';foreach($ds as $e){$d=$e['decision'];$s=$e['state'];$v=$e['validation'];$wc=count($v['errors'])+count($v['warnings']);$setSrc=($s['roomSetpointSource']??'none')==='eBUS'?' eBUS':'';
            $r.='<tr><td>'.htmlspecialchars((string)$e['name']).'</td><td>'.htmlspecialchars((string)$s['calendarMode']).'</td><td>'.htmlspecialchars((string)$d['reasonCode']).'</td><td>'.$this->fmt($d['position'],'%').'</td><td>'.$this->fmt($d['slat'],'%').'</td><td>'.($s['directSun']?'☀ ja':'–').'</td><td>'.$this->fmt($s['sunAzimuth'],'°').'</td><td>'.$this->fmt($s['sunElevation'],'°').'</td><td>'.$this->fmt($s['radiationFiltered'],' W/m²').'</td><td>'.$this->fmt($s['roomTemp'],' °C').'</td><td>'.$this->fmt($s['roomSetpoint'],' °C').htmlspecialchars($setSrc).'</td><td>'.htmlspecialchars((string)$s['privacyLevel']).'</td><td>'.($s['sleepActive']?($s['wakeRelease']?'wach':'schläft'):'–').'</td><td>'.($d['controlLockDesired']?'🔒':'–').'</td><td>'.($wc?'⚠ '.$wc:'✓').'</td></tr>';}
        return'<div style="font-family:Arial,sans-serif;overflow-x:auto"><h3>SmartShading — Tages-Simulation</h3><div><b>Keine Aktor- oder KNX-Sperrbefehle</b> · Aktualisiert: '.date('Y-m-d H:i:s').'</div><table style="width:100%;border-collapse:collapse;white-space:nowrap"><thead><tr><th>Behang</th><th>Modus</th><th>Entscheidung</th><th>Pos.</th><th>Lamelle</th><th>Direkte Sonne</th><th>Azimut</th><th>Sonnenhöhe</th><th>Strahlung</th><th>Raum Ist</th><th>Raum Soll</th><th>Sichtschutz</th><th>Schlaf</th><th>Sperre</th><th>Konfig.</th></tr></thead><tbody>'.$r.'</tbody></table></div>';
    }

    private function protocolHtml(string $date): string
    {
        $es=array_values(array_filter($this->protocol(),fn($e)=>($e['date']??'')===$date));if(!$es)return'<div><h3>Tagesprotokoll '.htmlspecialchars($date).'</h3><p>Noch keine Ereignisse protokolliert.</p></div>';
        $r='';foreach(array_slice($es,-400) as $e){$c=$e['context']??[];$src=($c['roomSetpointSource']??'none')==='eBUS'?' eBUS':'';
            $r.='<tr><td>'.htmlspecialchars((string)$e['time']).'</td><td>'.htmlspecialchars((string)$e['blind']).'</td><td>'.htmlspecialchars((string)$e['calendarMode']).'</td><td>'.htmlspecialchars((string)$e['reasonCode']).'</td><td>'.$this->fmt($e['targetPosition'],'%').'</td><td>'.(($c['directSun']??false)?'☀ ja':'–').'</td><td>'.$this->fmt($c['sunAzimuth']??null,'°').'</td><td>'.$this->fmt($c['sunElevation']??null,'°').'</td><td>'.$this->fmt($c['radiation']??null,' W/m²').'</td><td>'.$this->fmt($c['roomTemp']??null,' °C').'</td><td>'.$this->fmt($c['roomSetpoint']??null,' °C').htmlspecialchars($src).'</td><td>'.htmlspecialchars((string)($c['privacyLevel']??'')).'</td><td>'.(($c['sleepActive']??false)?(($c['wakeRelease']??false)?'wach':'schläft'):'–').'</td><td>'.(($e['controlLockDesired']??false)?'🔒':'–').'</td><td>'.htmlspecialchars((string)$e['reason']).'</td></tr>';}
        return'<div style="overflow-x:auto"><h3>Tagesprotokoll '.htmlspecialchars($date).'</h3><div>'.count($es).' protokollierte Änderungen/Snapshots.</div><table style="width:100%;border-collapse:collapse;white-space:nowrap"><thead><tr><th>Zeit</th><th>Behang</th><th>Modus</th><th>Grund</th><th>Pos.</th><th>Direkte Sonne</th><th>Azimut</th><th>Sonnenhöhe</th><th>Strahlung</th><th>Raum Ist</th><th>Raum Soll</th><th>Sichtschutz</th><th>Schlaf</th><th>Sperre</th><th>Erklärung</th></tr></thead><tbody>'.$r.'</tbody></table></div>';
    }
}
