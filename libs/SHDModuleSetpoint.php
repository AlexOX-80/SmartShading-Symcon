<?php
declare(strict_types=1);

trait SHDModuleSetpoint
{
    private function effectiveRoomSetpoint(array $b): array
    {
        // 1. Explicit setpoint on the blind/room always wins.
        $id=(int)($b['roomSetpointID']??0);
        $v=$this->num($id);
        if($v!==null&&$v>=5.0&&$v<=35.0){
            return ['value'=>$v,'source'=>'room','id'=>$id];
        }

        // 2. Use the explicitly configured global/eBUS variable.
        // If the field is still empty, auto-discover eBUS once and persist the
        // discovered variable into the property so it becomes visible in the UI.
        $global=(int)$this->ReadPropertyInteger('RoomSetpointFallbackID');
        if($global<=0){
            $detected=$this->eBusRoomSetpointID();
            if($detected>0){
                try{
                    IPS_SetProperty($this->InstanceID,'RoomSetpointFallbackID',$detected);
                }catch(Throwable $e){}
                $global=$detected;
            }
        }

        $gv=$this->num($global);
        if($gv!==null&&$gv>=5.0&&$gv<=35.0){
            return ['value'=>$gv,'source'=>'eBUS','id'=>$global];
        }

        // 3. If no usable variable exists, use a deterministic comfort default.
        // This avoids hidden automatic sources and prevents values such as 0 °C
        // from triggering false overheating decisions.
        return ['value'=>22.0,'source'=>'default','id'=>0];
    }
}
