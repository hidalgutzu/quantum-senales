<?php
/* ════════════════════════════════════════════════════════════════
   QUANTUM · Robot cuantitativo de alertas a Telegram (24/7)
   Mismo motor que la página: 11 factores, kNN + regresión logística,
   validación fuera de muestra, filtro de llegada tarde y bitácora real.
   Súbelo junto a la página y programa un cron cada 15 minutos.
   ════════════════════════════════════════════════════════════════ */

// ── 1. CONFIGURA ESTO ──
$BOT_TOKEN = 'PEGA_AQUI_EL_TOKEN_DE_BOTFATHER';
$CHAT_ID   = 'PEGA_AQUI_TU_CHAT_ID';
$SECRET    = 'cambia-esta-clave';        // para ejecutarlo por URL: quantum-alertas.php?key=...
$LANG      = 'es';                       // idioma de los mensajes: 'es' o 'en'

$COINS   = ['BTC','ETH','SOL','XRP','ADA'];
$TFS     = ['4h','1d','1w','1M'];
$CAPITAL = 1000;   // USD
$RISK    = 1.0;    // % máximo del capital que aceptas perder por trade
$MAX_LEV = 3;      // apalancamiento máximo

// ── 2. MODELO (igual que la página; no hace falta tocarlo) ──
$M = ['look'=>30,'sl'=>1.5,'tp'=>2,'tp2'=>3.5,'k'=>25,'alpha'=>4,'thr'=>.55,'strong'=>.60,'late'=>.75,
      'split'=>.7,'iters'=>120,'lr'=>.5,'lambda'=>.01,'minVal'=>8,'margin'=>.03];
$B  = $M['tp']/$M['sl'];          // 1.333R por acierto
$BE = 1/(1+$B);                   // equilibrio 42.9%
$HIGHER  = ['4h'=>'1d','1d'=>'1w','1w'=>'1M'];
$STATE   = __DIR__.'/quantum-state.json';
$JOURNAL = __DIR__.'/quantum-journal.json';   // la página lo lee para mostrar el historial real 24/7

if (php_sapi_name() !== 'cli' && ($_GET['key'] ?? '') !== $SECRET) { http_response_code(403); exit('Acceso denegado'); }
header('Content-Type: text/plain; charset=utf-8');
@set_time_limit(300);

/* ── HTTP ── */
function http_get($url){
  if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20,CURLOPT_USERAGENT=>'QUANTUM/2.0']);
    $r = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return $code===200 ? $r : false;
  }
  return @file_get_contents($url,false,stream_context_create(['http'=>['timeout'=>20]]));
}
function tg($text){
  global $BOT_TOKEN,$CHAT_ID;
  $url="https://api.telegram.org/bot$BOT_TOKEN/sendMessage";
  $data=http_build_query(['chat_id'=>$CHAT_ID,'text'=>$text,'parse_mode'=>'HTML','disable_web_page_preview'=>'true']);
  if (function_exists('curl_init')) {
    $ch=curl_init($url); curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$data,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>15]);
    $r=curl_exec($ch); curl_close($ch);
  } else {
    $r=@file_get_contents($url,false,stream_context_create(['http'=>['method'=>'POST','header'=>'Content-Type: application/x-www-form-urlencoded','content'=>$data,'timeout'=>15]]));
  }
  $j=json_decode((string)$r,true); return !empty($j['ok']);
}
function klines($sym,$tf){
  foreach (['https://api.binance.com','https://data-api.binance.vision'] as $h) {
    $r=http_get("$h/api/v3/klines?symbol={$sym}USDT&interval=$tf&limit=1000");
    $j=$r?json_decode($r,true):null;
    if (is_array($j) && count($j)>40) {
      $D=['t'=>[],'o'=>[],'h'=>[],'l'=>[],'c'=>[],'v'=>[]];
      foreach($j as $k){ $D['t'][]=(float)$k[0]; $D['o'][]=(float)$k[1]; $D['h'][]=(float)$k[2]; $D['l'][]=(float)$k[3]; $D['c'][]=(float)$k[4]; $D['v'][]=(float)$k[5]; }
      return $D;
    }
  }
  return null;
}

/* ── Indicadores base ── */
function clampv($v,$a,$b){ return min($b,max($a,$v)); }
function ema($a,$p){ $n=count($a); $o=array_fill(0,$n,NAN); if($n<$p) return $o; $e=array_sum(array_slice($a,0,$p))/$p; $o[$p-1]=$e; $k=2/($p+1); for($i=$p;$i<$n;$i++){ $e=$a[$i]*$k+$e*(1-$k); $o[$i]=$e; } return $o; }
function rsi($c,$p=14){ $n=count($c); $o=array_fill(0,$n,NAN); if($n<=$p) return $o; $g=0;$l=0; for($i=1;$i<=$p;$i++){ $d=$c[$i]-$c[$i-1]; if($d>0)$g+=$d; else $l-=$d; } $g/=$p;$l/=$p; $o[$p]=$l?100-100/(1+$g/$l):100;
  for($i=$p+1;$i<$n;$i++){ $d=$c[$i]-$c[$i-1]; $g=($g*($p-1)+max($d,0))/$p; $l=($l*($p-1)+max(-$d,0))/$p; $o[$i]=$l?100-100/(1+$g/$l):100; } return $o; }
function macdH($c){ $f=ema($c,12); $s=ema($c,26); $n=count($c); $m=[]; $st=-1; for($i=0;$i<$n;$i++){ $m[$i]=$f[$i]-$s[$i]; if($st<0&&is_finite($m[$i]))$st=$i; }
  $o=array_fill(0,$n,NAN); if($st<0) return $o; $sg=ema(array_slice($m,$st),9); for($i=$st;$i<$n;$i++) $o[$i]=$m[$i]-$sg[$i-$st]; return $o; }
function atr($h,$l,$c,$p=14){ $n=count($c); $o=array_fill(0,$n,NAN); if($n<=$p) return $o; $s=0; for($i=1;$i<=$p;$i++) $s+=max($h[$i]-$l[$i],abs($h[$i]-$c[$i-1]),abs($l[$i]-$c[$i-1])); $a=$s/$p; $o[$p]=$a;
  for($i=$p+1;$i<$n;$i++){ $a=($a*($p-1)+max($h[$i]-$l[$i],abs($h[$i]-$c[$i-1]),abs($l[$i]-$c[$i-1])))/$p; $o[$i]=$a; } return $o; }

/* ── Motor cuantitativo (idéntico a la página) ── */
function buildFeatures($D){
  $c=$D['c'];$h=$D['h'];$l=$D['l'];$v=$D['v'];$n=count($c);
  $e20=ema($c,20);$e50=ema($c,50);$e200=ema($c,200);$r=rsi($c);$mh=macdH($c);$a=atr($h,$l,$c);
  $X=array_fill(0,$n,null);
  for($i=60;$i<$n;$i++){
    $A=$a[$i]; if(!is_finite($A)||$A<=0||!is_finite($e50[$i])||!is_finite($mh[$i])||!is_finite($r[$i])) continue;
    $s=0;$q=0;$hi=-INF;$lo=INF;$vs=0;$vq=0;
    for($k=$i-19;$k<=$i;$k++){ $s+=$c[$k];$q+=$c[$k]*$c[$k]; if($h[$k]>$hi)$hi=$h[$k]; if($l[$k]<$lo)$lo=$l[$k]; $vs+=$v[$k];$vq+=$v[$k]*$v[$k]; }
    $m=$s/20; $sd=sqrt(max($q/20-$m*$m,1e-18)); $vm=$vs/20; $vsd=sqrt(max($vq/20-$vm*$vm,1e-18));
    $am=0;$ac=0; for($k=max(0,$i-99);$k<=$i;$k++) if(is_finite($a[$k])){ $am+=$a[$k];$ac++; } $am/=$ac;
    $X[$i]=[($e20[$i]-$e50[$i])/$A,($c[$i]-$e50[$i])/$A,is_finite($e200[$i])?($c[$i]-$e200[$i])/$A:0,($c[$i]-$c[$i-3])/$A,($c[$i]-$c[$i-10])/$A,($r[$i]-50)/50,$mh[$i]/$A,($c[$i]-$m)/(2*$sd),$A/$am-1,$hi>$lo?($c[$i]-$lo)/($hi-$lo)-.5:0,$vsd>0?clampv(($v[$i]-$vm)/$vsd,-3,3):0];
  }
  return [$X,$a];
}
function buildLabels($D,$a){
  global $M; $n=count($D['c']); $U=array_fill(0,$n,null); $Dn=array_fill(0,$n,null);
  for($i=0;$i<$n-2;$i++){
    $A=$a[$i]; if(!is_finite($A)) continue;
    $e=$D['o'][$i+1]; $end=min($n-2,$i+$M['look']); $su=$e-$M['sl']*$A; $tu=$e+$M['tp']*$A; $sd=$e+$M['sl']*$A; $td=$e-$M['tp']*$A; $ru=null; $rd=null;
    for($k=$i+1;$k<=$end;$k++){
      if($ru===null){ if($D['l'][$k]<=$su)$ru=0; elseif($D['h'][$k]>=$tu)$ru=1; }
      if($rd===null){ if($D['h'][$k]>=$sd)$rd=0; elseif($D['l'][$k]<=$td)$rd=1; }
      if($ru!==null&&$rd!==null) break;
    }
    if($end-$i>=$M['look']){ if($ru===null)$ru=0; if($rd===null)$rd=0; }
    $U[$i]=$ru; $Dn[$i]=$rd;
  }
  return ['U'=>$U,'D'=>$Dn];
}
function zStats($X,$idx){ $d=count($X[$idx[0]]); $m=array_fill(0,$d,0); $s=array_fill(0,$d,0); $n=count($idx);
  foreach($idx as $i) for($j=0;$j<$d;$j++) $m[$j]+=$X[$i][$j]; for($j=0;$j<$d;$j++) $m[$j]/=$n;
  foreach($idx as $i) for($j=0;$j<$d;$j++) $s[$j]+=($X[$i][$j]-$m[$j])**2; for($j=0;$j<$d;$j++){ $s[$j]=sqrt($s[$j]/$n); if(!$s[$j])$s[$j]=1; }
  return [$m,$s]; }
function zOf($x,$st){ $z=[]; foreach($x as $j=>$v) $z[$j]=clampv(($v-$st[0][$j])/$st[1][$j],-4,4); return $z; }
function sigm($t){ return 1/(1+exp(-$t)); }
function fitLogit($Z,$y){
  global $M; $n=count($Z); $d=count($Z[0]); $w=array_fill(0,$d+1,0.0);
  for($it=0;$it<$M['iters'];$it++){
    $g=array_fill(0,$d+1,0.0);
    for($i=0;$i<$n;$i++){ $z=$Z[$i]; $t=$w[$d]; for($j=0;$j<$d;$j++) $t+=$w[$j]*$z[$j]; $e=sigm($t)-$y[$i]; for($j=0;$j<$d;$j++) $g[$j]+=$e*$z[$j]; $g[$d]+=$e; }
    for($j=0;$j<$d;$j++) $w[$j]-=$M['lr']*($g[$j]/$n+$M['lambda']*$w[$j]); $w[$d]-=$M['lr']*$g[$d]/$n;
  }
  return $w;
}
function logitP($w,$z){ $d=count($z); $t=$w[$d]; for($j=0;$j<$d;$j++) $t+=$w[$j]*$z[$j]; return sigm($t); }
function fitModel($X,$L,$idx){
  global $BE; $st=zStats($X,$idx); $Z=[];$yU=[];$yD=[];
  foreach($idx as $i){ $Z[]=zOf($X[$i],$st); $yU[]=$L['U'][$i]; $yD[]=$L['D'][$i]; }
  $iu=[];$id=[]; foreach($yU as $k=>$y) if($y!==null)$iu[]=$k; foreach($yD as $k=>$y) if($y!==null)$id[]=$k;
  $bu=$iu?array_sum(array_map(fn($k)=>$yU[$k],$iu))/count($iu):$BE; $bd=$id?array_sum(array_map(fn($k)=>$yD[$k],$id))/count($id):$BE;
  $wU=count($iu)>=30?fitLogit(array_map(fn($k)=>$Z[$k],$iu),array_map(fn($k)=>$yU[$k],$iu)):null;
  $wD=count($id)>=30?fitLogit(array_map(fn($k)=>$Z[$k],$id),array_map(fn($k)=>$yD[$k],$id)):null;
  return compact('st','Z','yU','yD','bu','bd','wU','wD');
}
function predict($md,$x){
  global $M; $z=zOf($x,$md['st']); $n=count($md['Z']); $dist=[];
  for($i=0;$i<$n;$i++){ $a=$md['Z'][$i]; $s=0; foreach($z as $j=>$v){ $q=$a[$j]-$v; $s+=$q*$q; } $dist[$i]=$s; }
  asort($dist);
  $wu=0;$cu=0;$wd=0;$cd=0;
  foreach($dist as $i=>$_){ if($cu>=$M['k']&&$cd>=$M['k']) break;
    if($cu<$M['k']&&$md['yU'][$i]!==null){ $wu+=$md['yU'][$i]; $cu++; }
    if($cd<$M['k']&&$md['yD'][$i]!==null){ $wd+=$md['yD'][$i]; $cd++; } }
  $ku=($wu+$M['alpha']*$md['bu'])/($cu+$M['alpha']); $kd=($wd+$M['alpha']*$md['bd'])/($cd+$M['alpha']);
  $lu=$md['wU']?logitP($md['wU'],$z):$md['bu']; $ld=$md['wD']?logitP($md['wD'],$z):$md['bd'];
  return ['pu'=>($ku+$lu)/2,'pd'=>($kd+$ld)/2];
}
function validate($X,$L,$n){
  global $M,$B; $idx=[]; for($i=0;$i<$n-2;$i++) if($X[$i]!==null&&$L['U'][$i]!==null&&$L['D'][$i]!==null) $idx[]=$i;
  $empty=['n'=>0,'hit'=>NAN,'ev'=>NAN]; if(count($idx)<100) return $empty;
  $cut=$idx[(int)floor(count($idx)*$M['split'])];
  $tr=array_values(array_filter($idx,fn($i)=>$i<$cut-$M['look'])); $te=array_values(array_filter($idx,fn($i)=>$i>=$cut));
  if(count($tr)<60||count($te)<10) return $empty;
  $md=fitModel($X,$L,$tr); $t=0;$w=0;$R=0;$last=-1e9;
  foreach($te as $i){ if($i<$last+3) continue;
    $p=predict($md,$X[$i]); $up=$p['pu']>=$p['pd']; $pp=$up?$p['pu']:$p['pd']; $y=$up?$L['U'][$i]:$L['D'][$i];
    if($pp<$M['thr']||$pp*$B-(1-$pp)<=0) continue;
    $t++; $w+=$y; $R+=$y?$B:-1; $last=$i; }
  return ['n'=>$t,'hit'=>$t?$w/$t:NAN,'ev'=>$t?$R/$t:NAN];
}
function analyze($D){
  global $M,$B,$BE; $n=count($D['c']); $q=$n-2;
  [$X,$a]=buildFeatures($D); if($X[$q]===null) return null;
  $L=buildLabels($D,$a); $V=validate($X,$L,$n);
  $tr=[]; for($i=0;$i<$q;$i++) if($X[$i]!==null&&($L['U'][$i]!==null||$L['D'][$i]!==null)) $tr[]=$i;
  if(count($tr)<60) return null;
  $md=fitModel($X,$L,$tr); $P=predict($md,$X[$q]);
  $side=$P['pu']>=$P['pd']?1:-1; $p=$side>0?$P['pu']:$P['pd']; $ev=$p*$B-(1-$p); $A=$a[$q]; $px=$D['c'][$n-1];
  $validated=$V['n']>=$M['minVal']&&$V['hit']>$BE+$M['margin']; $late=$side*($px-$D['c'][$q])>$M['late']*$A;
  $reason=$p<$M['thr']?'lowp':($ev<=0?'ev':(!$validated?'noval':($late?'late':null)));
  return ['sg'=>$reason?0:$side,'side'=>$side,'p'=>$p,'ev'=>$ev,'reason'=>$reason,'V'=>$V,'px'=>$px,'barT'=>$D['t'][$q],
    'zoneA'=>$px-$side*.5*$A,'sl'=>$px-$side*$M['sl']*$A,'tp1'=>$px+$side*$M['tp']*$A,'tp2'=>$px+$side*$M['tp2']*$A,'D'=>$D];
}
function strongCtx($r,$up){
  global $M; if(!$r||!$r['sg']) return [false,false];
  $against=$up&&$up['side']===-$r['side']&&$up['p']>=$M['thr'];
  return [$r['p']>=$M['strong']&&!$against,$against];
}

/* ── Formato ── */
function fp($v){ $a=abs($v); $d=$a>=1000?0:($a>=100?1:($a>=10?2:4)); return '$'.number_format($v,$d,'.',','); }
function fm($v){ return '$'.number_format(round($v),0,'.',','); }
function pc($v){ return ($v>0?'+':'').number_format($v*100,1).'%'; }
function pp($v){ return round($v*100).'%'; }
function fR($v){ return ($v>=0?'+':'−').number_format(abs($v),2).'R'; }
$TXT=['es'=>['buy'=>'🟢 <b>COMPRAR','sell'=>'🔴 <b>VENDER','price'=>'Precio','zone'=>'Zona','stop'=>'Stop','t1'=>'Objetivo 1','t2'=>'Objetivo 2','prob'=>'Probabilidad','ev'=>'Valor esperado','val'=>'Validado','max'=>'Máximo a meter','risk'=>'riesgo','tf'=>['4h'=>'4H','1d'=>'1D','1w'=>'1S','1M'=>'1M'],'ok'=>"✅ <b>QUANTUM conectado</b>\nEl robot cuantitativo está funcionando.",'res'=>['win'=>'✅ Objetivo alcanzado','loss'=>'❌ Stop tocado']],
      'en'=>['buy'=>'🟢 <b>BUY','sell'=>'🔴 <b>SELL','price'=>'Price','zone'=>'Zone','stop'=>'Stop','t1'=>'Target 1','t2'=>'Target 2','prob'=>'Probability','ev'=>'Expected value','val'=>'Validated','max'=>'Max size','risk'=>'risk','tf'=>['4h'=>'4H','1d'=>'1D','1w'=>'1W','1M'=>'1M'],'ok'=>"✅ <b>QUANTUM connected</b>\nThe quant bot is running.",'res'=>['win'=>'✅ Target hit','loss'=>'❌ Stop hit']]];
$X=$TXT[$LANG]??$TXT['es'];

// ── Prueba ──
if (isset($_GET['test']) || in_array('--test',$argv ?? [])) { echo tg($X['ok'])?"Mensaje de prueba enviado\n":"Error: revisa token y chat ID\n"; exit; }

/* ── Ciclo principal ── */
$state=is_file($STATE)?(json_decode(file_get_contents($STATE),true)?:[]):[];
$journal=is_file($JOURNAL)?(json_decode(file_get_contents($JOURNAL),true)?:[]):[];
$sent=0; $res=[];
foreach($COINS as $coin){
  foreach($TFS as $tf){ $D=klines($coin,$tf); $res[$coin][$tf]=$D?analyze($D):null; }
  foreach($TFS as $tf){
    $r=$res[$coin][$tf]; $up=isset($HIGHER[$tf])?$res[$coin][$HIGHER[$tf]]:null; [$strong,$against]=strongCtx($r,$up);
    echo str_pad("$coin $tf",9).($r?('p='.pp($r['p']).' '.($r['side']>0?'↑':'↓').' '.($r['sg']?'SEÑAL':'espera:'.$r['reason']).($strong?' FUERTE':'')):'sin historia suficiente');
    if($strong){
      $key="$coin|$tf"; $tag=$r['sg'].'@'.$r['barT'];
      if(($state[$key]??'')!==$tag){
        $fs=$r['p']-(1-$r['p'])/$B; $use=min($RISK,max(0,.25*$fs*100)); $dist=abs($r['px']-$r['sl'])/$r['px'];
        $notional=min($CAPITAL*$use/100/$dist,$CAPITAL*$MAX_LEV); $lev=$notional/$CAPITAL;
        $msg=($r['sg']>0?$X['buy']:$X['sell'])." $coin · ".$X['tf'][$tf]."</b>\n"
          .$X['price'].": ".fp($r['px'])."\n".$X['zone'].": ".fp(min($r['zoneA'],$r['px']))." – ".fp(max($r['zoneA'],$r['px']))
          ."\n".$X['stop'].": ".fp($r['sl'])." (".pc(($r['sl']-$r['px'])/$r['px']).")"
          ."\n".$X['t1'].": ".fp($r['tp1'])." · ".$X['t2'].": ".fp($r['tp2'])
          ."\n\n".$X['prob'].": ".pp($r['p'])." · ".$X['ev'].": ".fR($r['ev'])
          ."\n".$X['val'].": ".pp($r['V']['hit'])." / ".$r['V']['n']
          ."\n".$X['max'].": ".fm($notional).($lev>1?' ('.number_format($lev,1).'×)':'')." · ".$X['risk']." ".fm($notional*$dist);
        if(tg($msg)){ $state[$key]=$tag; $sent++; echo '  → ALERTA ENVIADA'; }
        $id="$coin|$tf|{$r['sg']}|{$r['barT']}";
        if(!in_array($id,array_column($journal,'id'),true))
          $journal[]=['id'=>$id,'coin'=>$coin,'tf'=>$tf,'dir'=>$r['sg'],'t'=>$r['barT'],'at'=>time()*1000,'entry'=>$r['px'],'sl'=>$r['sl'],'tp1'=>$r['tp1'],'p'=>round($r['p'],4),'status'=>'open'];
      }
    }
    echo "\n";
  }
}

/* ── Bitácora: califica las señales abiertas contra las velas nuevas ── */
foreach($journal as &$e){
  if($e['status']!=='open') continue; $r=$res[$e['coin']][$e['tf']]??null; if(!$r) continue; $D=$r['D']; $n=count($D['t']);
  $i0=-1; for($k=0;$k<$n;$k++) if($D['t'][$k]>$e['t']){ $i0=$k; break; } if($i0<0) continue;
  for($k=$i0;$k<$n;$k++){
    if($e['dir']>0){ if($D['l'][$k]<=$e['sl']){$e['status']='loss';break;} if($D['h'][$k]>=$e['tp1']){$e['status']='win';break;} }
    else { if($D['h'][$k]>=$e['sl']){$e['status']='loss';break;} if($D['l'][$k]<=$e['tp1']){$e['status']='win';break;} }
  }
  if($e['status']==='open'&&$n-$i0>$M['look']) $e['status']='exp';
  if($e['status']==='win'||$e['status']==='loss') tg($X['res'][$e['status']]." · {$e['coin']} ".$X['tf'][$e['tf']]);
}
unset($e);
$journal=array_slice($journal,-300);
file_put_contents($STATE,json_encode($state));
file_put_contents($JOURNAL,json_encode($journal));
$w=count(array_filter($journal,fn($e)=>$e['status']==='win')); $l=count(array_filter($journal,fn($e)=>$e['status']==='loss'));
echo "\nAlertas enviadas: $sent · Historial: $w objetivos / $l stops".($w+$l?' ('.pp($w/($w+$l)).' real)':'')."\n";
