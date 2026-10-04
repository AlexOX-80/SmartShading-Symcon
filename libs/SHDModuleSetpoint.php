<?php
declare(strict_types=1);

trait SHDModuleSetpoint
{
    private function effectiveRoomSetpoint(array $b): array
    {
        $id=(int)($b['roomSetpointID']??0);
        $v=$this->num($id);
        if($v!==null&&$v>=5.0&&$v<=35.0){
            return ['value'=>$v,'source'=>'room','id'=>$id];
        }

        $global=(int)$this->ReadPropertyInteger('RoomSetpointFallbackID');
        $gv=$this->num($global);
        if($gv!==null&&$gv>=5.0&&$gv<=35.0){
            return ['value'=>$gv,'source'=>'global','id'=>$global];
        }

        $fallback=$this->eBusRoomSetpointID();
        $fv=$this->num($fallback);
        if($fv!==null&&$fv>=5.0&&$fv<=35.0){
            return ['value'=>$fv,'source'=>'eBUS','id'=>$fallback];
        }

        return ['value'=>null,'source'=>'none','id'=>0];
    }
}
