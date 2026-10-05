<?php
declare(strict_types=1);

trait SHDModuleProtocolDownload
{
    public function GetConfigurationForm(): string
    {
        $form=json_decode($this->GetConfigurationFormBase(),true);
        if(!is_array($form)) return $this->GetConfigurationFormBase();

        $items=[];
        foreach($this->GetAvailableProtocolDates() as $date=>$count){
            $ts=strtotime((string)$date);
            $caption=((string)$date===date('Y-m-d')?'Heute – ':(((string)$date===date('Y-m-d',strtotime('-1 day')))?'Gestern – ':''))
                .date('d.m.Y',$ts).' · '.(int)$count.' Events';
            $items[]=[
                'type'=>'Button',
                'caption'=>$caption,
                'download'=>'SmartShading-Tagesprotokoll-'.$date.'.json.gz',
                'onClick'=>'$json=SHD_GetDailyProtocolJSON($id, "'.$date.'"); $gz=gzencode($json, 9); echo "data:application/gzip;base64,".base64_encode($gz);'
            ];
        }
        if(count($items)===0)$items[]=['type'=>'Label','caption'=>'Noch keine gespeicherten Tagesprotokolle vorhanden.'];

        $actions=$form['actions']??[];
        $newActions=[];
        foreach($actions as $action){
            if(($action['caption']??'')==='Heutiges Tagesprotokoll herunterladen (.json.gz)'){
                $newActions[]=[
                    'type'=>'PopupButton',
                    'caption'=>'Tagesprotokoll nach Datum herunterladen',
                    'popup'=>[
                        'caption'=>'Gespeicherte Tagesprotokolle',
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
