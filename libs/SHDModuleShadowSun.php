<?php
declare(strict_types=1);

trait SHDModuleShadowSun
{
    private function state(array $b,array $mem): array
    {
        $s=$this->stateBase($b,$mem);
        $s['radiationMeasured']=$s['radiationFiltered']??null;
        $s['radiationSource']='measured';
        $s['shadowSunID']=0;
        $s['shadowSun']=null;

        // Test bridge only in Shadow mode. It never changes the real radiation variable.
        if(!$this->ReadPropertyBoolean('ShadowMode')) return $s;

        $id=$this->shadowSunVariableID();
        $sun=$this->boolVar($id);
        if($id<=0||$sun===null) return $s;

        $testRadiation=$sun?600.0:0.0;
        $s['radiation']=$testRadiation;
        $s['radiationFiltered']=$testRadiation;
        $s['radiationSource']='shadow-sun-bool';
        $s['shadowSunID']=$id;
        $s['shadowSun']=$sun;
        return $s;
    }

    private function protocolEvent(string $k,array $e): array
    {
        $event=$this->protocolEventBase($k,$e);
        $s=$e['state']??[];
        if(!isset($event['context'])||!is_array($event['context']))$event['context']=[];
        $event['context']['radiationSource']=$s['radiationSource']??'measured';
        $event['context']['radiationMeasured']=$s['radiationMeasured']??null;
        $event['context']['shadowSunID']=$s['shadowSunID']??0;
        $event['context']['shadowSun']=$s['shadowSun']??null;
        return $event;
    }

    private function shadowSunVariableID(): int
    {
        static $cached=null;
        if($cached!==null)return $cached;

        $best=0;
        foreach(IPS_GetObjectList() as $id){
            if(!IPS_VariableExists($id))continue;
            try{$v=IPS_GetVariable($id);}catch(Throwable $e){continue;}
            if((int)($v['VariableType']??-1)!==0)continue;
            if(mb_strtolower(trim(IPS_GetName($id)))!=='sonne')continue;
            $best=$id;
            break;
        }
        return $cached=$best;
    }
}
