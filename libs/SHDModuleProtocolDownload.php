<?php
declare(strict_types=1);

trait SHDModuleProtocolDownload
{
    public function GetConfigurationForm(): string
    {
        $form=json_decode($this->GetConfigurationFormBase(),true);
        if(!is_array($form)) return $this->GetConfigurationFormBase();

        $retention=max(1,min(14,$this->ReadPropertyInteger('ProtocolRetentionDays')));
        $items=[];
        for($i=0;$i<$retention;$i++){
            $ts=strtotime('-'.$i.' day');
            $date=date('Y-m-d',$ts);
            $caption=$i===0?'Heute – '.date('d.m.Y',$ts):($i===1?'Gestern – '.date('d.m.Y',$ts):date('d.m.Y',$ts));
            $items[]=[
                'type'=>'Button',
                'caption'=>$caption,
                'download'=>'SmartShading-Tagesprotokoll-'.$date.'.json.gz',
                'onClick'=>'$json=SHD_GetDailyProtocolJSON($id, "'.$date.'"); $gz=gzencode($json, 9); echo "data:application/gzip;base64,".base64_encode($gz);'
            ];
        }

        $actions=$form['actions']??[];
        $newActions=[];
        foreach($actions as $action){
            if(($action['caption']??'')==='Heutiges Tagesprotokoll herunterladen (.json.gz)'){
                $newActions[]=[
                    'type'=>'PopupButton',
                    'caption'=>'Tagesprotokoll nach Datum herunterladen',
                    'popup'=>[
                        'caption'=>'Tagesprotokoll auswählen',
                        'closeCaption'=>'Schließen',
                        'items'=>$items
                    ]
                ];
                continue;
            }
            $newActions[]=$action;
        }
        $form['actions']=$newActions;
        return json_encode($form,JSON_UNESCAPED_UNICODE);
    }
}
