<?php
declare(strict_types=1);

trait SHDModuleUI
{

    public function Create(): void
        {
            parent::Create();
            foreach ([
                'Active'=>true,'ShadowMode'=>true,'AutoDiscoverLegacy'=>true
            ] as $n=>$v) $this->RegisterPropertyBoolean($n,$v);
            foreach ([
                'EvaluationInterval'=>300,'SunAzimuthID'=>0,'SunElevationID'=>0,'RadiationID'=>0,
                'OutsideTemperatureID'=>0,'OutdoorBrightnessID'=>0,'WindAlarmID'=>0,'HailAlarmID'=>0,
                'RainAlarmID'=>0,'EmergencyID'=>0,'ProtocolRetentionDays'=>3,'ProtocolSnapshotMinutes'=>60
            ] as $n=>$v) $this->RegisterPropertyInteger($n,$v);
            foreach ([
                'SunOnThreshold'=>300.0,'SunOffThreshold'=>180.0,'DaylightLuxThreshold'=>100.0,
                'PrivacyRatioThreshold'=>1.0,'ColdNightOn'=>-5.0,'ColdNightOff'=>-3.0,
                'LegacyFacade0Azimuth'=>320.0,'LegacyFacade1Azimuth'=>50.0,
                'LegacyFacade2Azimuth'=>140.0,'LegacyFacade3Azimuth'=>230.0
            ] as $n=>$v) $this->RegisterPropertyFloat($n,$v);
            $this->RegisterPropertyString('Blinds','[]');
            $this->RegisterAttributeString('DecisionMemory','{}');
            $this->RegisterAttributeString('ProtocolLog','[]');
            $this->RegisterVariableString('Dashboard',$this->Translate('Dashboard'),'~HTMLBox');
            $this->RegisterVariableString('DayProtocol',$this->Translate('Day Protocol'),'~HTMLBox');
            $this->RegisterVariableString('StatusSummary',$this->Translate('Status Summary'));
            $this->RegisterVariableString('Inventory',$this->Translate('Inventory'));
            $this->RegisterVariableInteger('LastEvaluation',$this->Translate('Last Evaluation'),'~UnixTimestamp');
            $this->RegisterTimer('EvaluateTimer',0,'SHD_Evaluate($_IPS["TARGET"]);');
        }

    public function ApplyChanges(): void
        {
            parent::ApplyChanges();
            $active=$this->ReadPropertyBoolean('Active');
            $this->SetTimerInterval('EvaluateTimer',$active?max(30,$this->ReadPropertyInteger('EvaluationInterval'))*1000:0);
            $this->SetStatus($active?102:104);
            if($active) $this->Evaluate();
        }

    public function GetConfigurationForm(): string
        {
            $rows=[];$configured=$this->configured();
            foreach($configured as $b) $rows[]=$b;
            if($this->ReadPropertyBoolean('AutoDiscoverLegacy')) {
                foreach($this->legacy() as $k=>$b) if(!isset($configured[(string)$k])) { $b['rowColor']='#FFFFC0';$rows[]=$b; }
            }
            $sv=fn(string $n,string $c,array $t=[0,1,2])=>['type'=>'SelectVariable','name'=>$n,'caption'=>$c,'validVariableTypes'=>$t];
            $form=[
                'elements'=>[
                    ['type'=>'CheckBox','name'=>'Active','caption'=>'Active'],
                    ['type'=>'CheckBox','name'=>'ShadowMode','caption'=>'Shadow mode — v0.3 never writes actuators or KNX locks'],
                    ['type'=>'CheckBox','name'=>'AutoDiscoverLegacy','caption'=>'Discover legacy blind configurations'],
                    ['type'=>'NumberSpinner','name'=>'EvaluationInterval','caption'=>'Evaluation interval (seconds)','minimum'=>30],
                    ['type'=>'ExpansionPanel','caption'=>'Environment','items'=>[
                        $sv('SunAzimuthID','Sun azimuth',[1,2]),$sv('SunElevationID','Sun elevation',[1,2]),
                        $sv('RadiationID','Solar radiation',[1,2]),$sv('OutsideTemperatureID','Outside temperature',[1,2]),
                        $sv('OutdoorBrightnessID','Outdoor brightness lux',[1,2]),$sv('WindAlarmID','Wind alarm',[0]),
                        $sv('HailAlarmID','Hail alarm',[0]),$sv('RainAlarmID','Rain/wetness',[0]),$sv('EmergencyID','Emergency/fire release',[0])
                    ]],
                    ['type'=>'ExpansionPanel','caption'=>'Thresholds','items'=>[
                        ['type'=>'NumberSpinner','name'=>'SunOnThreshold','caption'=>'Sun protection ON W/m²','digits'=>1],
                        ['type'=>'NumberSpinner','name'=>'SunOffThreshold','caption'=>'Sun protection OFF W/m²','digits'=>1],
                        ['type'=>'NumberSpinner','name'=>'DaylightLuxThreshold','caption'=>'Daylight available above lux','digits'=>1],
                        ['type'=>'NumberSpinner','name'=>'PrivacyRatioThreshold','caption'=>'Night privacy indoor/outdoor ratio','digits'=>2],
                        ['type'=>'NumberSpinner','name'=>'ColdNightOn','caption'=>'Cold-night ON below °C','digits'=>1],
                        ['type'=>'NumberSpinner','name'=>'ColdNightOff','caption'=>'Cold-night OFF above °C','digits'=>1]
                    ]],
                    ['type'=>'ExpansionPanel','caption'=>'Legacy facade azimuths','items'=>[
                        ['type'=>'NumberSpinner','name'=>'LegacyFacade0Azimuth','caption'=>'Facade 0 °','minimum'=>0,'maximum'=>359],
                        ['type'=>'NumberSpinner','name'=>'LegacyFacade1Azimuth','caption'=>'Facade 1 °','minimum'=>0,'maximum'=>359],
                        ['type'=>'NumberSpinner','name'=>'LegacyFacade2Azimuth','caption'=>'Facade 2 °','minimum'=>0,'maximum'=>359],
                        ['type'=>'NumberSpinner','name'=>'LegacyFacade3Azimuth','caption'=>'Facade 3 °','minimum'=>0,'maximum'=>359]
                    ]],
                    ['type'=>'ExpansionPanel','caption'=>'Day protocol','items'=>[
                        ['type'=>'NumberSpinner','name'=>'ProtocolRetentionDays','caption'=>'Retention days','minimum'=>1,'maximum'=>14],
                        ['type'=>'NumberSpinner','name'=>'ProtocolSnapshotMinutes','caption'=>'Snapshot every minutes','minimum'=>15,'maximum'=>240]
                    ]],
                    ['type'=>'List','name'=>'Blinds','caption'=>'Blinds','add'=>true,'delete'=>true,'rowCount'=>12,'values'=>$rows,
                        'columns'=>$this->columns(),'form'=>$this->editForm()]
                ],
                'actions'=>[
                    ['type'=>'Label','caption'=>'v0.3-shadow calculates and logs only. No actuator/lock writes.'],
                    ['type'=>'Button','caption'=>'Evaluate now','onClick'=>'SHD_Evaluate($id);'],
                    ['type'=>'Button','caption'=>'Show validation','onClick'=>'echo SHD_ValidateConfiguration($id);'],
                    ['type'=>'Button','caption'=>'Show inventory JSON','onClick'=>'echo SHD_GetInventoryJSON($id);'],
                    ['type'=>'Button','caption'=>'Show today protocol JSON','onClick'=>'echo SHD_GetDailyProtocolJSON($id, "");'],
                    ['type'=>'Button','caption'=>'Clear day protocol','onClick'=>'SHD_ClearProtocol($id);']
                ],
                'status'=>[['code'=>102,'icon'=>'active','caption'=>'Active'],['code'=>104,'icon'=>'inactive','caption'=>'Inactive']]
            ];
            return json_encode($form,JSON_UNESCAPED_UNICODE);
        }

    private function columns(): array { return[['name'=>'enabled','caption'=>'On','width'=>'50px','add'=>true,'edit'=>['type'=>'CheckBox']],['name'=>'name','caption'=>'Name','width'=>'auto','add'=>'','edit'=>['type'=>'ValidationTextBox']],['name'=>'room','caption'=>'Room','width'=>'100px','add'=>'','edit'=>['type'=>'ValidationTextBox']],['name'=>'type','caption'=>'Type','width'=>'90px','add'=>'venetian','edit'=>['type'=>'Select','options'=>[['caption'=>'Venetian','value'=>'venetian'],['caption'=>'Roller','value'=>'roller']]]],['name'=>'facadeAzimuth','caption'=>'Azimuth','width'=>'75px','add'=>0,'edit'=>['type'=>'NumberSpinner','minimum'=>0,'maximum'=>359]],['name'=>'calendarModeID','caption'=>'Calendar','width'=>'90px','add'=>0,'edit'=>['type'=>'SelectVariable','validVariableTypes'=>[1]]],['name'=>'positionStatusID','caption'=>'Pos status','width'=>'90px','add'=>0,'edit'=>['type'=>'SelectVariable','validVariableTypes'=>[1,2]]],['name'=>'source','caption'=>'Source','width'=>'70px','add'=>'module','save'=>true]]; }

    private function editForm(): array
        {
            $sv=fn(string $n,string $c,array $t=[0,1,2])=>['type'=>'SelectVariable','name'=>$n,'caption'=>$c,'validVariableTypes'=>$t];
            return[['type'=>'CheckBox','name'=>'enabled','caption'=>'Enabled'],['type'=>'ValidationTextBox','name'=>'name','caption'=>'Name'],['type'=>'ValidationTextBox','name'=>'room','caption'=>'Room'],['type'=>'Select','name'=>'type','caption'=>'Blind type','options'=>[['caption'=>'Venetian','value'=>'venetian'],['caption'=>'Roller','value'=>'roller']]],['type'=>'NumberSpinner','name'=>'facadeAzimuth','caption'=>'Facade azimuth °','minimum'=>0,'maximum'=>359],['type'=>'RowLayout','items'=>[['type'=>'NumberSpinner','name'=>'sunFrom','caption'=>'Sun from','minimum'=>0,'maximum'=>359],['type'=>'NumberSpinner','name'=>'sunTo','caption'=>'Sun to','minimum'=>0,'maximum'=>359]]],['type'=>'ExpansionPanel','caption'=>'Calendar / sleep / release','items'=>[$sv('calendarModeID','Calendar / old Aktuelles Programm',[1]),['type'=>'Select','name'=>'defaultMode','caption'=>'Default mode','options'=>[['caption'=>'AUTO','value'=>'AUTO'],['caption'=>'SLEEP','value'=>'SLEEP'],['caption'=>'FORCE CLOSED','value'=>'FORCE_CLOSED'],['caption'=>'FORCE OPEN','value'=>'FORCE_OPEN'],['caption'=>'MANUAL','value'=>'MANUAL_MODE']]],$sv('sleepStateID','Sleep state',[0]),$sv('wakeReleaseID','Wake release',[0]),$sv('daylightReleaseID','Daylight release / all-awake',[0]),$sv('controlLockID','KNX control lock target (shadow only)',[0]),$sv('panicID','Panic button',[0])]],['type'=>'ExpansionPanel','caption'=>'Actuator / feedback','items'=>[$sv('positionControlID','Position control',[1,2]),$sv('positionStatusID','Position feedback',[1,2]),$sv('slatControlID','Slat control',[1,2]),$sv('slatStatusID','Slat feedback',[1,2])]],['type'=>'ExpansionPanel','caption'=>'Room / brightness / door','items'=>[$sv('roomTempID','Room temperature',[1,2]),$sv('roomSetpointID','Room setpoint',[1,2]),$sv('indoorBrightnessID','Indoor brightness lux',[1,2]),$sv('doorContactID','Door contact',[0])]],['type'=>'ExpansionPanel','caption'=>'Privacy','items'=>[['type'=>'NumberSpinner','name'=>'privacyDayPosition','caption'=>'Day min closure %','minimum'=>0,'maximum'=>100],['type'=>'NumberSpinner','name'=>'privacyDaySlat','caption'=>'Day min slat %','minimum'=>0,'maximum'=>100],['type'=>'NumberSpinner','name'=>'privacyNightPosition','caption'=>'Night min closure %','minimum'=>0,'maximum'=>100],['type'=>'NumberSpinner','name'=>'privacyNightSlat','caption'=>'Night min slat %','minimum'=>0,'maximum'=>100]]],['type'=>'ExpansionPanel','caption'=>'Sleep / cold / safety targets','items'=>[['type'=>'NumberSpinner','name'=>'sleepPosition','caption'=>'Sleep min closure %','minimum'=>0,'maximum'=>100],['type'=>'NumberSpinner','name'=>'sleepSlat','caption'=>'Sleep min slat %','minimum'=>0,'maximum'=>100],['type'=>'NumberSpinner','name'=>'coldNightPosition','caption'=>'Cold-night min closure %','minimum'=>0,'maximum'=>100],['type'=>'NumberSpinner','name'=>'doorOpenMaxPosition','caption'=>'Door-open max closure %','minimum'=>0,'maximum'=>100],['type'=>'NumberSpinner','name'=>'safetyPosition','caption'=>'Wind/hail safety position %','minimum'=>0,'maximum'=>100]]],['type'=>'NumberSpinner','name'=>'blindID','caption'=>'Legacy blind ID','visible'=>false],['type'=>'NumberSpinner','name'=>'configVariableID','caption'=>'Legacy config variable ID','visible'=>false],['type'=>'ValidationTextBox','name'=>'source','caption'=>'Source','visible'=>false]];
        }

}
