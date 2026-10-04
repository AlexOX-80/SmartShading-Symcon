<?php
declare(strict_types=1);

trait SHDModuleUI
{
    public function Create(): void
    {
        parent::Create();
        foreach(['Active'=>true,'ShadowMode'=>true,'AutoDiscoverLegacy'=>true] as $n=>$v)$this->RegisterPropertyBoolean($n,$v);
        foreach(['EvaluationInterval'=>300,'SunAzimuthID'=>0,'SunElevationID'=>0,'RadiationID'=>0,'OutsideTemperatureID'=>0,'OutdoorBrightnessID'=>0,'RoomSetpointFallbackID'=>0,'WindAlarmID'=>0,'HailAlarmID'=>0,'RainAlarmID'=>0,'EmergencyID'=>0,'ProtocolRetentionDays'=>3,'ProtocolSnapshotMinutes'=>60] as $n=>$v)$this->RegisterPropertyInteger($n,$v);
        foreach(['SunOnThreshold'=>300.0,'SunOffThreshold'=>180.0,'DaylightLuxThreshold'=>100.0,'PrivacyRatioThreshold'=>1.0,'ColdNightOn'=>-5.0,'ColdNightOff'=>-3.0,'LegacyFacade0Azimuth'=>320.0,'LegacyFacade1Azimuth'=>50.0,'LegacyFacade2Azimuth'=>140.0,'LegacyFacade3Azimuth'=>230.0] as $n=>$v)$this->RegisterPropertyFloat($n,$v);
        $this->RegisterPropertyString('Blinds','[]');
        $this->RegisterAttributeString('DecisionMemory','{}');
        $this->RegisterAttributeString('ProtocolLog','[]');
        $this->RegisterVariableString('Dashboard','Dashboard','~HTMLBox');
        $this->RegisterVariableString('DayProtocol','Tagesprotokoll','~HTMLBox');
        $this->RegisterVariableString('StatusSummary','Statusübersicht');
        $this->RegisterVariableString('Inventory','Inventar');
        $this->RegisterVariableInteger('LastEvaluation','Letzte Auswertung','~UnixTimestamp');
        $this->RegisterTimer('EvaluateTimer',0,'SHD_Evaluate($_IPS["TARGET"]);');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();$active=$this->ReadPropertyBoolean('Active');$this->SetTimerInterval('EvaluateTimer',$active?max(30,$this->ReadPropertyInteger('EvaluationInterval'))*1000:0);$this->SetStatus($active?102:104);if($active)$this->Evaluate();
    }

    public function GetConfigurationForm(): string
    {
        $rows=[];
        foreach($this->blinds() as $b){
            $row=$b;
            $az=SHDMath::facadeAzimuth($b);
            $row['facadeDisplay']=$az===null?'–':number_format($az,0,',','.').'°';
            $row['positionDisplay']=$this->objectDisplayName((int)($b['positionControlID']??0));
            $row['feedbackDisplay']=$this->feedbackDisplay($b);
            $rows[]=$row;
        }
        $sv=fn(string $n,string $c,array $t=[0,1,2])=>['type'=>'SelectVariable','name'=>$n,'caption'=>$c,'validVariableTypes'=>$t];
        $si=fn(string $n,string $c)=>['type'=>'SelectInstance','name'=>$n,'caption'=>$c];
        $help=fn(string $caption,string $title,string $text)=>[
            'type'=>'PopupButton','caption'=>$caption,
            'popup'=>['caption'=>$title,'closeCaption'=>'Schließen','items'=>[['type'=>'Label','caption'=>$text]]]
        ];

        $form=['elements'=>[
            ['type'=>'CheckBox','name'=>'Active','caption'=>'Aktiv'],
            ['type'=>'CheckBox','name'=>'ShadowMode','caption'=>'Simulationsmodus (keine Fahr- oder KNX-Sperrbefehle)'],
            ['type'=>'CheckBox','name'=>'AutoDiscoverLegacy','caption'=>'Bestehende Behang-Konfiguration automatisch übernehmen'],
            ['type'=>'NumberSpinner','name'=>'EvaluationInterval','caption'=>'Auswertungsintervall in Sekunden','minimum'=>30],

            ['type'=>'ExpansionPanel','caption'=>'Umgebung und Sensoren','items'=>[
                $help('? Hilfe','Umgebung und Sensoren','Hier werden die zentralen Messwerte zugeordnet. Sonnenazimut und Sonnenhöhe bestimmen die geometrische Besonnung. Strahlung bzw. Helligkeit entscheiden, ob tatsächlich Sonne oder Tageslicht vorhanden ist. Der globale Raumtemperatur-Sollwert dient als Fallback für Behänge ohne eigenen Raum-Sollwert; bleibt er leer, wird eBUS automatisch gesucht. Sicherheitsmeldungen haben Vorrang vor Komfortregeln.'),
                $sv('SunAzimuthID','Sonnenazimut',[1,2]),$sv('SunElevationID','Sonnenhöhe',[1,2]),
                $sv('RadiationID','Solarstrahlung in W/m²',[1,2]),$sv('OutsideTemperatureID','Außentemperatur',[1,2]),
                $sv('OutdoorBrightnessID','Außenhelligkeit in Lux (optional)',[1,2]),
                $sv('RoomSetpointFallbackID','Raumtemperatur Soll global / eBUS-Fallback',[1,2]),
                $sv('WindAlarmID','Windalarm',[0]),$sv('HailAlarmID','Hagelalarm',[0]),$sv('RainAlarmID','Regen/Nässe',[0]),$sv('EmergencyID','Gefahr/Brand-Freigabe',[0])
            ]],

            ['type'=>'ExpansionPanel','caption'=>'Schwellwerte','items'=>[
                $help('? Hilfe','Schwellwerte','Die Ein- und Ausschaltschwellen mit Hysterese verhindern häufiges Umschalten. Sichtschutz Nacht wird bei vorhandenen Innen-/Außen-Helligkeitswerten aus deren Verhältnis abgeleitet. Der Kälteschutz wirkt nur nachts.'),
                ['type'=>'NumberSpinner','name'=>'SunOnThreshold','caption'=>'Sonnenschutz EIN ab W/m²','digits'=>1],
                ['type'=>'NumberSpinner','name'=>'SunOffThreshold','caption'=>'Sonnenschutz AUS unter W/m²','digits'=>1],
                ['type'=>'NumberSpinner','name'=>'DaylightLuxThreshold','caption'=>'Tageslicht verfügbar ab Lux','digits'=>1],
                ['type'=>'NumberSpinner','name'=>'PrivacyRatioThreshold','caption'=>'Nacht-Sichtschutz ab Verhältnis Innen/Außen','digits'=>2],
                ['type'=>'NumberSpinner','name'=>'ColdNightOn','caption'=>'Nacht-Kälteschutz EIN unter °C','digits'=>1],
                ['type'=>'NumberSpinner','name'=>'ColdNightOff','caption'=>'Nacht-Kälteschutz AUS über °C','digits'=>1]
            ]],

            ['type'=>'ExpansionPanel','caption'=>'Fassadenausrichtung Altanlage','items'=>[
                $help('? Hilfe','Fassadenausrichtung','Diese Werte übersetzen die alten Fassadenkennungen 0–3 in echte Azimutwinkel des Hauses. 0° = Nord, 90° = Ost, 180° = Süd, 270° = West. Die voreingestellten Werte berücksichtigen die Drehung deines Hauses.'),
                ['type'=>'NumberSpinner','name'=>'LegacyFacade0Azimuth','caption'=>'Fassade 0 – Azimut °','minimum'=>0,'maximum'=>359],
                ['type'=>'NumberSpinner','name'=>'LegacyFacade1Azimuth','caption'=>'Fassade 1 – Azimut °','minimum'=>0,'maximum'=>359],
                ['type'=>'NumberSpinner','name'=>'LegacyFacade2Azimuth','caption'=>'Fassade 2 – Azimut °','minimum'=>0,'maximum'=>359],
                ['type'=>'NumberSpinner','name'=>'LegacyFacade3Azimuth','caption'=>'Fassade 3 – Azimut °','minimum'=>0,'maximum'=>359]
            ]],

            ['type'=>'ExpansionPanel','caption'=>'Tagesprotokoll','items'=>[
                $help('? Hilfe','Tagesprotokoll','Das Tagesprotokoll speichert relevante Zustands- und Entscheidungsänderungen sowie regelmäßige Momentaufnahmen. Damit kann später nachvollzogen werden, warum ein Behang zu einem bestimmten Zeitpunkt eine bestimmte Zielposition erhalten hätte.'),
                ['type'=>'NumberSpinner','name'=>'ProtocolRetentionDays','caption'=>'Aufbewahrung in Tagen','minimum'=>1,'maximum'=>14],
                ['type'=>'NumberSpinner','name'=>'ProtocolSnapshotMinutes','caption'=>'Momentaufnahme alle Minuten','minimum'=>15,'maximum'=>240]
            ]],

            ['type'=>'List','name'=>'Blinds','caption'=>'Behänge','add'=>true,'delete'=>true,'rowCount'=>12,'values'=>$rows,'columns'=>$this->columns(),'form'=>$this->editForm()]
        ],'actions'=>[
            ['type'=>'Label','caption'=>'Simulationsmodus: Es werden keine Fahrbefehle und keine KNX-Bediensperren geschrieben.'],
            ['type'=>'Button','caption'=>'Jetzt auswerten','onClick'=>'SHD_Evaluate($id);'],
            ['type'=>'Button','caption'=>'Konfiguration prüfen','onClick'=>'echo SHD_ValidateConfiguration($id);'],
            ['type'=>'Button','caption'=>'Inventar als JSON anzeigen','onClick'=>'echo SHD_GetInventoryJSON($id);'],
            ['type'=>'Button','caption'=>'Heutiges Tagesprotokoll herunterladen (.json.gz)','download'=>'SmartShading-Tagesprotokoll-'.date('Y-m-d').'.json.gz','onClick'=>'$json=SHD_GetDailyProtocolJSON($id, ""); $gz=gzencode($json, 9); echo "data:application/gzip;base64,".base64_encode($gz);'],
            ['type'=>'Button','caption'=>'Tagesprotokoll löschen','confirm'=>'Soll das Tagesprotokoll wirklich gelöscht werden?','onClick'=>'SHD_ClearProtocol($id);']
        ],'status'=>[['code'=>102,'icon'=>'active','caption'=>'Aktiv'],['code'=>104,'icon'=>'inactive','caption'=>'Inaktiv']]];
        return json_encode($form,JSON_UNESCAPED_UNICODE);
    }

    private function columns(): array
    {
        return[
            ['name'=>'enabled','caption'=>'Aktiv','width'=>'55px','add'=>true,'edit'=>['type'=>'CheckBox']],
            ['name'=>'name','caption'=>'Behang','width'=>'auto','add'=>'','edit'=>['type'=>'ValidationTextBox']],
            ['name'=>'room','caption'=>'Raum','width'=>'100px','add'=>'','edit'=>['type'=>'ValidationTextBox']],
            ['name'=>'type','caption'=>'Typ','width'=>'85px','add'=>'venetian','edit'=>['type'=>'Select','options'=>[['caption'=>'Jalousie','value'=>'venetian'],['caption'=>'Rollladen','value'=>'roller']]]],
            ['name'=>'facadeDisplay','caption'=>'Fassade','width'=>'80px'],
            ['name'=>'calendarModeID','caption'=>'Aktueller Modus','width'=>'130px','add'=>0,'edit'=>['type'=>'SelectVariable','validVariableTypes'=>[1]]],
            ['name'=>'scheduleEventID','caption'=>'Wochenplan','width'=>'130px','add'=>0,'edit'=>['type'=>'SelectEvent']],
            ['name'=>'positionDisplay','caption'=>'Position KNX','width'=>'180px'],
            ['name'=>'feedbackDisplay','caption'=>'Rückmeldung','width'=>'105px'],
            ['name'=>'source','caption'=>'Herkunft','width'=>'75px','add'=>'module','save'=>true]
        ];
    }

    private function editForm(): array
    {
        $sv=fn(string $n,string $c,array $t=[0,1,2])=>['type'=>'SelectVariable','name'=>$n,'caption'=>$c,'validVariableTypes'=>$t];
        $si=fn(string $n,string $c)=>['type'=>'SelectInstance','name'=>$n,'caption'=>$c];
        $help=fn(string $title,string $text)=>['type'=>'PopupButton','caption'=>'? Hilfe','popup'=>['caption'=>$title,'closeCaption'=>'Schließen','items'=>[['type'=>'Label','caption'=>$text]]]];
        return[
            ['type'=>'CheckBox','name'=>'enabled','caption'=>'Aktiv'],
            ['type'=>'ValidationTextBox','name'=>'name','caption'=>'Name'],
            ['type'=>'ValidationTextBox','name'=>'room','caption'=>'Raum'],
            ['type'=>'Select','name'=>'type','caption'=>'Behangtyp','options'=>[['caption'=>'Jalousie','value'=>'venetian'],['caption'=>'Rollladen','value'=>'roller']]],
            ['type'=>'NumberSpinner','name'=>'facadeAzimuth','caption'=>'Fassadenazimut °','minimum'=>0,'maximum'=>359],
            ['type'=>'RowLayout','items'=>[['type'=>'NumberSpinner','name'=>'sunFrom','caption'=>'Sonne ab Azimut °','minimum'=>0,'maximum'=>359],['type'=>'NumberSpinner','name'=>'sunTo','caption'=>'Sonne bis Azimut °','minimum'=>0,'maximum'=>359]]],

            ['type'=>'ExpansionPanel','caption'=>'Kalender, Schlafen und Freigaben','items'=>[
                $help('Kalender, Schlafen und Freigaben','„Aktueller Modus“ ist die Variable „Aktuelles Programm“ und wird für die Entscheidung im aktuellen Moment verwendet. „Wochenplan/Kalender“ ist das Symcon-Ereignis, das diesen Modus zeitgesteuert setzt. Beides wird getrennt gespeichert. Schlaf- und Tageslichtfreigaben können später vom Wecker-/Personenmodul kommen.'),
                $sv('calendarModeID','Aktueller Modus („Aktuelles Programm“)',[1]),
                ['type'=>'SelectEvent','name'=>'scheduleEventID','caption'=>'Wochenplan / Kalender-Ereignis'],
                ['type'=>'Select','name'=>'defaultMode','caption'=>'Standardmodus ohne Kalender','options'=>[['caption'=>'Automatik','value'=>'AUTO'],['caption'=>'Schlafen','value'=>'SLEEP'],['caption'=>'Immer geschlossen','value'=>'FORCE_CLOSED'],['caption'=>'Immer offen','value'=>'FORCE_OPEN'],['caption'=>'Manuell','value'=>'MANUAL_MODE']]],
                $sv('sleepStateID','Schlafstatus (optional)',[0]),
                $sv('wakeReleaseID','Aufsteh-/Weckfreigabe (optional)',[0]),
                $sv('daylightReleaseID','Tageslichtfreigabe / Alle-wach-Gruppe (optional)',[0]),
                $sv('controlLockID','KNX-Bediensperre Zielvariable (nur Simulation)',[0]),
                $sv('panicID','Paniktaste Eingang',[0])
            ]],

            ['type'=>'ExpansionPanel','caption'=>'Antrieb und Rückmeldungen','items'=>[
                $help('Antrieb und Rückmeldungen','„Position anfahren“ und „Lamelle anfahren“ sind die vorhandenen KNX-Instanzen. Bei deiner Anlage ist die jeweilige Rückmelde-GA normalerweise bereits in derselben KNX-Instanz hinterlegt. Ein separates Istwert-Objekt ist daher optional und nur nötig, wenn die Rückmeldung separat in Symcon angelegt wurde.'),
                $si('positionControlID','KNX-Instanz Position anfahren'),$sv('positionStatusID','Separater Positions-Istwert (optional)',[1,2]),
                $si('slatControlID','KNX-Instanz Lamelle anfahren'),$sv('slatStatusID','Separater Lamellen-Istwert (optional)',[1,2])
            ]],

            ['type'=>'ExpansionPanel','caption'=>'Raum, Helligkeit und Tür','items'=>[
                $help('Raum, Helligkeit und Tür','Raumtemperatur und Solltemperatur entscheiden zwischen passiver Solarwärme und Überhitzungsschutz. Innenhelligkeit wird zusammen mit Außenhelligkeit für den tatsächlichen Sichtschutzbedarf verwendet. Ein geöffneter Türkontakt kann das Herunterfahren begrenzen.'),
                $sv('roomTempID','Raumtemperatur Ist',[1,2]),$sv('roomSetpointID','Raumtemperatur Soll',[1,2]),
                $sv('indoorBrightnessID','Innenhelligkeit Lux (optional)',[1,2]),$sv('doorContactID','Türkontakt',[0])
            ]],

            ['type'=>'ExpansionPanel','caption'=>'Sichtschutz','items'=>[
                $help('Sichtschutz','Sichtschutz ist eine Mindestschließung, kein starrer Zielwert. Wenn Sonnenschutz oder Überhitzung stärker schließen müssen, dürfen sie das. Bei ausreichendem Tageslicht und ohne tatsächlichen Sichtschutzbedarf greift keine Sichtschutzgrenze.'),
                ['type'=>'NumberSpinner','name'=>'privacyDayPosition','caption'=>'Sichtschutz Tag – Mindestschließung %','minimum'=>0,'maximum'=>100],
                ['type'=>'NumberSpinner','name'=>'privacyDaySlat','caption'=>'Sichtschutz Tag – Lamelle mindestens %','minimum'=>0,'maximum'=>100],
                ['type'=>'NumberSpinner','name'=>'privacyNightPosition','caption'=>'Sichtschutz Nacht – Mindestschließung %','minimum'=>0,'maximum'=>100],
                ['type'=>'NumberSpinner','name'=>'privacyNightSlat','caption'=>'Sichtschutz Nacht – Lamelle mindestens %','minimum'=>0,'maximum'=>100]
            ]],

            ['type'=>'ExpansionPanel','caption'=>'Schlafen, Kälteschutz und Sicherheit','items'=>[
                $help('Schlafen, Kälteschutz und Sicherheit','Im Schlafzustand soll der Behang vollständig schließen und eine KNX-Bediensperre angefordert werden. Die Aufstehfreigabe darf morgens trotz Schlaf-Kalender wieder Tageslicht zulassen. Kälteschutz wirkt nur nachts. Sicherheitsereignisse haben Vorrang.'),
                ['type'=>'NumberSpinner','name'=>'sleepPosition','caption'=>'Schlafen – Mindestschließung %','minimum'=>0,'maximum'=>100],
                ['type'=>'NumberSpinner','name'=>'sleepSlat','caption'=>'Schlafen – Lamelle mindestens %','minimum'=>0,'maximum'=>100],
                ['type'=>'NumberSpinner','name'=>'coldNightPosition','caption'=>'Nacht-Kälteschutz – Mindestschließung %','minimum'=>0,'maximum'=>100],
                ['type'=>'NumberSpinner','name'=>'doorOpenMaxPosition','caption'=>'Tür offen – maximal zulässige Schließung %','minimum'=>0,'maximum'=>100],
                ['type'=>'NumberSpinner','name'=>'safetyPosition','caption'=>'Wind/Hagel – Sicherheitsposition %','minimum'=>0,'maximum'=>100]
            ]],

            ['type'=>'NumberSpinner','name'=>'blindID','caption'=>'Legacy Behang-ID','visible'=>false],
            ['type'=>'NumberSpinner','name'=>'configVariableID','caption'=>'Legacy Konfigurationsvariable-ID','visible'=>false],
            ['type'=>'ValidationTextBox','name'=>'source','caption'=>'Herkunft','visible'=>false]
        ];
    }

    private function objectDisplayName(int $id): string
    {
        if($id<=0||!IPS_ObjectExists($id))return 'fehlt';
        return IPS_GetName($id).' (#'.$id.')';
    }

    private function feedbackDisplay(array $b): string
    {
        $explicit=(int)($b['positionStatusID']??0);
        if($explicit>0&&IPS_VariableExists($explicit))return 'separat';
        $control=(int)($b['positionControlID']??0);
        if($control>0&&IPS_InstanceExists($control)){
            $valueID=@IPS_GetObjectIDByIdent('Value',$control);
            if($valueID!==false&&IPS_VariableExists((int)$valueID))return 'integriert';
        }
        if($control>0&&IPS_VariableExists($control))return 'integriert';
        return 'fehlt';
    }
}
