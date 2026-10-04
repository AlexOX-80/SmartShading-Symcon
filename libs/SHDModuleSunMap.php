<?php
declare(strict_types=1);

trait SHDModuleSunMap
{
    private function sunMapHtml(array $decisions): string
    {
        $blinds=$this->blinds();
        $cx=450.0;$cy=320.0;$houseW=330.0;$houseH=220.0;
        $facades=[
            0=>$this->ReadPropertyFloat('LegacyFacade0Azimuth'),
            1=>$this->ReadPropertyFloat('LegacyFacade1Azimuth'),
            2=>$this->ReadPropertyFloat('LegacyFacade2Azimuth'),
            3=>$this->ReadPropertyFloat('LegacyFacade3Azimuth')
        ];
        $houseRotation=$facades[0];
        if($houseRotation>180)$houseRotation-=360.0;

        $first=null;foreach($decisions as $e){$first=$e;break;}
        $s=$first['state']??[];
        $sunAz=$s['sunAzimuth']??null;$sunEl=$s['sunElevation']??null;$rad=$s['radiationFiltered']??null;

        $svg=[];
        $svg[]='<svg viewBox="0 0 900 650" width="100%" height="auto" role="img" aria-label="Sonnenkarte Haus">';
        $svg[]='<defs><filter id="shdShadow"><feDropShadow dx="0" dy="2" stdDeviation="3" flood-opacity="0.18"/></filter></defs>';
        $svg[]='<rect x="0" y="0" width="900" height="650" rx="18" fill="#f8fafc"/>';
        $svg[]='<text x="450" y="38" text-anchor="middle" font-size="24" font-weight="700" fill="#111827">SmartShading — Sonnenkarte</text>';
        $svg[]='<text x="450" y="63" text-anchor="middle" font-size="14" fill="#475569">Draufsicht · schematische Fensterpositionen nach Fassade</text>';

        // Cardinal orientation.
        $svg[]='<text x="450" y="96" text-anchor="middle" font-size="18" font-weight="700" fill="#334155">N</text>';
        $svg[]='<line x1="450" y1="105" x2="450" y2="130" stroke="#334155" stroke-width="2"/><polygon points="450,101 445,110 455,110" fill="#334155"/>';
        $svg[]='<text x="790" y="322" text-anchor="middle" font-size="16" fill="#64748b">O</text>';
        $svg[]='<text x="450" y="612" text-anchor="middle" font-size="16" fill="#64748b">S</text>';
        $svg[]='<text x="110" y="322" text-anchor="middle" font-size="16" fill="#64748b">W</text>';

        // Sun position around the building, azimuth 0=N / 90=E / 180=S / 270=W.
        if($sunAz!==null){
            $a=deg2rad((float)$sunAz);$r=255.0;$sx=$cx+sin($a)*$r;$sy=$cy-cos($a)*$r;
            $svg[]='<line x1="'.round($sx,1).'" y1="'.round($sy,1).'" x2="'.$cx.'" y2="'.$cy.'" stroke="#f59e0b" stroke-width="4" stroke-dasharray="10 8" opacity="0.75"/>';
            $svg[]='<circle cx="'.round($sx,1).'" cy="'.round($sy,1).'" r="26" fill="#fbbf24" stroke="#f59e0b" stroke-width="3" filter="url(#shdShadow)"/>';
            $svg[]='<text x="'.round($sx,1).'" y="'.round($sy+5,1).'" text-anchor="middle" font-size="23">☀</text>';
        }

        // House outline. Fassade 0 points towards its configured azimuth.
        $rot=round($houseRotation,1);
        $svg[]='<g transform="rotate('.$rot.' '.$cx.' '.$cy.')">';
        $svg[]='<rect x="'.($cx-$houseW/2).'" y="'.($cy-$houseH/2).'" width="'.$houseW.'" height="'.$houseH.'" rx="8" fill="#e2e8f0" stroke="#475569" stroke-width="4" filter="url(#shdShadow)"/>';
        $svg[]='<rect x="'.($cx-$houseW/2+22).'" y="'.($cy-$houseH/2+22).'" width="'.($houseW-44).'" height="'.($houseH-44).'" rx="5" fill="#f8fafc" stroke="#cbd5e1" stroke-width="2"/>';
        $svg[]='<text x="'.$cx.'" y="'.($cy-8).'" text-anchor="middle" font-size="22" font-weight="700" fill="#334155">WOHNHAUS</text>';
        $svg[]='<text x="'.$cx.'" y="'.($cy+20).'" text-anchor="middle" font-size="13" fill="#64748b">Fassaden '.round($facades[0]).'° / '.round($facades[1]).'° / '.round($facades[2]).'° / '.round($facades[3]).'°</text>';
        $svg[]='</g>';

        $groups=[0=>[],1=>[],2=>[],3=>[]];
        foreach($blinds as $key=>$b){
            if(!isset($decisions[(string)$key]))continue;
            $az=SHDMath::facadeAzimuth($b);if($az===null)continue;
            $best=0;$bestDiff=999.0;
            foreach($facades as $idx=>$faz){$d=abs(SHDMath::signedAngle((float)$az-(float)$faz));if($d<$bestDiff){$bestDiff=$d;$best=$idx;}}
            $groups[$best][]=[$key,$b,$decisions[(string)$key]];
        }

        foreach($groups as $idx=>$items){
            $n=count($items);if($n===0)continue;$faz=(float)$facades[$idx];$a=deg2rad($faz);
            $normalX=sin($a);$normalY=-cos($a);$tanX=cos($a);$tanY=sin($a);
            $baseDist=195.0;$spacing=min(62.0,300.0/max(1,$n));
            foreach($items as $i=>$item){
                [$key,$b,$e]=$item;$offset=($i-($n-1)/2)*$spacing;
                $x=$cx+$normalX*$baseDist+$tanX*$offset;$y=$cy+$normalY*$baseDist+$tanY*$offset;
                $st=$e['state']??[];$d=$e['decision']??[];$direct=(bool)($st['directSun']??false);$reason=(string)($d['reasonCode']??'');$mode=(string)($st['calendarMode']??'');
                $fill='#94a3b8';
                if($mode==='MANUAL_MODE')$fill='#a855f7';
                elseif(in_array($reason,['SUN_PROTECTION','OVERHEATING'],true))$fill='#f97316';
                elseif($direct)$fill='#facc15';
                elseif($reason==='DAYLIGHT')$fill='#22c55e';
                elseif(in_array($reason,['SLEEP','PRIVACY_NIGHT','CALENDAR_CLOSED'],true))$fill='#3b82f6';
                $name=$this->sunMapShortName((string)($b['name']??$e['name']??'Behang'));
                $floor=$this->sunMapFloor((string)($b['name']??''));
                $tip=htmlspecialchars((string)($b['name']??'').' | '.$floor.' | '.($direct?'direkte Sonne':'keine direkte Sonne').' | '.$reason.' | Ziel '.$this->fmt($d['position']??null,'%'),ENT_QUOTES);
                $svg[]='<g><title>'.$tip.'</title><circle cx="'.round($x,1).'" cy="'.round($y,1).'" r="19" fill="'.$fill.'" stroke="#ffffff" stroke-width="3" filter="url(#shdShadow)"/>';
                $svg[]='<text x="'.round($x,1).'" y="'.round($y+4,1).'" text-anchor="middle" font-size="10" font-weight="700" fill="#0f172a">'.htmlspecialchars($floor).'</text>';
                $labelX=$x+$normalX*30;$labelY=$y+$normalY*30;
                $svg[]='<text x="'.round($labelX,1).'" y="'.round($labelY,1).'" text-anchor="middle" font-size="10" fill="#334155">'.htmlspecialchars($name).'</text></g>';
            }
        }

        $azText=$sunAz===null?'–':number_format((float)$sunAz,1,',','.').'°';$elText=$sunEl===null?'–':number_format((float)$sunEl,1,',','.').'°';$radText=$rad===null?'–':number_format((float)$rad,0,',','.').' W/m²';
        $svg[]='<rect x="22" y="530" width="856" height="92" rx="12" fill="#ffffff" stroke="#e2e8f0"/>';
        $svg[]='<text x="42" y="555" font-size="14" font-weight="700" fill="#111827">Sonne: Azimut '.$azText.' · Höhe '.$elText.' · Strahlung '.$radText.'</text>';
        $svg[]='<text x="42" y="582" font-size="12" fill="#475569">● gelb = direkte Sonne · ● orange = Sonnenschutz/Überhitzung · ● grün = Tageslicht · ● violett = Manuell · ● blau = geschlossen/Schlaf</text>';
        $svg[]='<text x="42" y="606" font-size="11" fill="#64748b">Marker = schematische Position auf der jeweiligen Fassade; EG/OG/UG im Marker. Aktualisiert '.date('H:i:s').'</text>';
        $svg[]='</svg>';
        return '<div style="font-family:Arial,sans-serif;max-width:1000px">'.implode('',$svg).'</div>';
    }

    private function sunMapShortName(string $name): string
    {
        $name=preg_replace('/\s*\(Höhe anfahren\)\s*/u','',$name)??$name;
        $repl=['Wohnzimmer'=>'Wohnen','Esszimmer'=>'Essen','Schlafzimmer'=>'Schlafen','Fenster'=>'Fen.','Schiebetüre'=>'Schiebe','Treppenhaus'=>'Treppe','Keller Hobyraum'=>'Keller'];
        return mb_substr(strtr($name,$repl),0,20);
    }

    private function sunMapFloor(string $name): string
    {
        $n=mb_strtolower($name);
        if(str_contains($n,'keller'))return 'UG';
        if(str_contains($n,'kind')||str_contains($n,'schlaf')||str_contains($n,'bad og')||str_contains($n,'treppenhaus'))return 'OG';
        return 'EG';
    }
}
