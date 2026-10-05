<?php
declare(strict_types=1);

trait SHDModuleProtocolStorage
{
    public function Create(): void
    {
        $this->CreateBase();
        for($i=0;$i<14;$i++){
            $this->RegisterAttributeString($this->protocolSlotIdent($i),'');
        }
        $this->RegisterAttributeInteger('ProtocolStorageVersion',0);
    }

    public function GetDailyProtocolJSON(string $date=''): string
    {
        if($date==='')$date=date('Y-m-d');
        $this->migrateLegacyProtocol();
        $events=$this->protocolEventsForDate($date);
        return json_encode([
            'schema'=>'SmartShadingDayProtocol/2',
            'date'=>$date,
            'generatedAt'=>date(DATE_ATOM),
            'eventCount'=>count($events),
            'events'=>$events
        ],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);
    }

    public function GetAvailableProtocolDates(): array
    {
        $this->migrateLegacyProtocol();
        $this->pruneProtocolSlots();
        $result=[];
        for($i=0;$i<14;$i++){
            $slot=$this->readProtocolSlot($i);
            $date=(string)($slot['date']??'');
            $events=$slot['events']??[];
            if($date===''||!is_array($events)||count($events)===0)continue;
            $result[$date]=count($events);
        }
        krsort($result,SORT_STRING);
        return $result;
    }

    public function ClearProtocol(): void
    {
        for($i=0;$i<14;$i++)$this->WriteAttributeString($this->protocolSlotIdent($i),'');
        $this->WriteAttributeString('ProtocolLog','[]');
        $this->WriteAttributeInteger('ProtocolStorageVersion',1);
        SetValueString($this->GetIDForIdent('DayProtocol'),$this->protocolHtml(date('Y-m-d')));
    }

    private function appendProtocol(array $new): void
    {
        $this->migrateLegacyProtocol();
        $grouped=[];
        foreach($new as $event){
            if(!is_array($event))continue;
            $date=(string)($event['date']??'');
            if($date===''){
                $ts=(int)($event['ts']??time());
                $date=date('Y-m-d',$ts);
                $event['date']=$date;
            }
            $grouped[$date][]=$event;
        }
        foreach($grouped as $date=>$events)$this->appendProtocolDay((string)$date,$events);
        $this->pruneProtocolSlots();
    }

    private function protocol(): array
    {
        $this->migrateLegacyProtocol();
        $this->pruneProtocolSlots();
        $all=[];
        for($i=0;$i<14;$i++){
            $slot=$this->readProtocolSlot($i);
            $events=$slot['events']??[];
            if(!is_array($events))continue;
            foreach($events as $event)if(is_array($event))$all[]=$event;
        }
        usort($all,fn($a,$b)=>(int)($a['ts']??0)<=>(int)($b['ts']??0));
        return $all;
    }

    private function appendProtocolDay(string $date,array $new): void
    {
        if(!$this->validProtocolDate($date)||count($new)===0)return;
        $slotIndex=$this->findProtocolSlot($date);
        if($slotIndex<0)$slotIndex=$this->allocateProtocolSlot();
        $slot=$this->readProtocolSlot($slotIndex);
        $events=(($slot['date']??'')===$date&&is_array($slot['events']??null))?$slot['events']:[];
        foreach($new as $event)if(is_array($event))$events[]=$event;
        if(count($events)>10000)$events=array_slice($events,-10000);
        $this->writeProtocolSlot($slotIndex,['date'=>$date,'events'=>array_values($events)]);
    }

    private function protocolEventsForDate(string $date): array
    {
        if(!$this->validProtocolDate($date))return[];
        $slotIndex=$this->findProtocolSlot($date);
        if($slotIndex<0)return[];
        $slot=$this->readProtocolSlot($slotIndex);
        $events=$slot['events']??[];
        return is_array($events)?array_values($events):[];
    }

    private function migrateLegacyProtocol(): void
    {
        if($this->ReadAttributeInteger('ProtocolStorageVersion')>=1)return;
        $legacy=json_decode($this->ReadAttributeString('ProtocolLog'),true);
        if(is_array($legacy)&&count($legacy)>0){
            $grouped=[];
            foreach($legacy as $event){
                if(!is_array($event))continue;
                $date=(string)($event['date']??'');
                if($date===''&&isset($event['ts']))$date=date('Y-m-d',(int)$event['ts']);
                if(!$this->validProtocolDate($date))continue;
                $grouped[$date][]=$event;
            }
            ksort($grouped,SORT_STRING);
            foreach($grouped as $date=>$events)$this->appendProtocolDay((string)$date,$events);
        }
        $this->WriteAttributeString('ProtocolLog','[]');
        $this->WriteAttributeInteger('ProtocolStorageVersion',1);
        $this->pruneProtocolSlots();
    }

    private function pruneProtocolSlots(): void
    {
        $retention=max(1,min(14,$this->ReadPropertyInteger('ProtocolRetentionDays')));
        $oldest=date('Y-m-d',strtotime('-'.($retention-1).' day'));
        $today=date('Y-m-d');
        for($i=0;$i<14;$i++){
            $slot=$this->readProtocolSlot($i);
            $date=(string)($slot['date']??'');
            if($date==='')continue;
            if(!$this->validProtocolDate($date)||$date<$oldest||$date>$today){
                $this->WriteAttributeString($this->protocolSlotIdent($i),'');
            }
        }
    }

    private function findProtocolSlot(string $date): int
    {
        for($i=0;$i<14;$i++){
            $slot=$this->readProtocolSlot($i);
            if(($slot['date']??'')===$date)return$i;
        }
        return-1;
    }

    private function allocateProtocolSlot(): int
    {
        $empty=-1;$oldestIndex=0;$oldestDate='9999-99-99';
        for($i=0;$i<14;$i++){
            $slot=$this->readProtocolSlot($i);
            $date=(string)($slot['date']??'');
            if($date===''){if($empty<0)$empty=$i;continue;}
            if($date<$oldestDate){$oldestDate=$date;$oldestIndex=$i;}
        }
        return$empty>=0?$empty:$oldestIndex;
    }

    private function readProtocolSlot(int $index): array
    {
        $raw=$this->ReadAttributeString($this->protocolSlotIdent($index));
        if($raw==='')return[];
        if(str_starts_with($raw,'gz:')){
            $bin=base64_decode(substr($raw,3),true);
            if($bin===false)return[];
            $decoded=@gzdecode($bin);
            if($decoded===false)return[];
            $raw=$decoded;
        }
        $data=json_decode($raw,true);
        return is_array($data)?$data:[];
    }

    private function writeProtocolSlot(int $index,array $data): void
    {
        $json=json_encode($data,JSON_UNESCAPED_UNICODE);
        if(!is_string($json))return;
        $gz=gzencode($json,6);
        $stored=$gz===false?$json:'gz:'.base64_encode($gz);
        $this->WriteAttributeString($this->protocolSlotIdent($index),$stored);
    }

    private function protocolSlotIdent(int $index): string
    {
        return'ProtocolDay'.str_pad((string)$index,2,'0',STR_PAD_LEFT);
    }

    private function validProtocolDate(string $date): bool
    {
        $dt=DateTimeImmutable::createFromFormat('!Y-m-d',$date);
        return$dt!==false&&$dt->format('Y-m-d')===$date;
    }
}
