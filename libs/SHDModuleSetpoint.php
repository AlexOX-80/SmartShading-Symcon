<?php
declare(strict_types=1);

trait SHDModuleSetpoint
{
    private function effectiveRoomSetpoint(array $b): array
    {
        // 1. Explicit room/blind setpoint has highest priority.
        $id=(int)($b['roomSetpointID']??0);
        $v=$this->num($id);
        if($v!==null&&$v>=5.0&&$v<=35.0){
            return ['value'=>$v,'source'=>'room','id'=>$id];
        }

        // 2. Global setpoint variable configured in the module.
        // If empty, auto-discover eBUS once and write the detected variable into
        // the visible property field. From then on it is treated as a configured
        // global source, not as a hidden eBUS fallback.
        $global=(int)$this->ReadPropertyInteger('RoomSetpointFallbackID');
        if($global<=0){
            $detected=$this->eBusRoomSetpointID();
            if($detected>0){
                try{IPS_SetProperty($this->InstanceID,'RoomSetpointFallbackID',$detected);}catch(Throwable $e){}
                $global=$detected;
            }
        }

        $gv=$this->num($global);
        if($gv!==null&&$gv>=5.0&&$gv<=35.0){
            return ['value'=>$gv,'source'=>'global','id'=>$global];
        }

        // 3. Deterministic configurable default when no usable variable exists.
        $default=$this->ReadPropertyFloat('DefaultRoomSetpoint');
        if($default<5.0||$default>35.0)$default=22.0;
        return ['value'=>$default,'source'=>'default','id'=>0];
    }
}
