<?php
/* ════════════════════════════════════════════════════════════════
   QUANTUM · Robot de alertas a Telegram (24/7)
   Súbelo a tu hosting y programa un cron cada 15 minutos.
   Usa el mismo motor que quantum-senales.html.
   ════════════════════════════════════════════════════════════════ */

// ── 1. CONFIGURA ESTO ──
$BOT_TOKEN = 'PEGA_AQUI_EL_TOKEN_DE_BOTFATHER';
$CHAT_ID   = 'PEGA_AQUI_TU_CHAT_ID';
$SECRET    = 'cambia-esta-clave';        // para ejecutarlo por URL: quantum-alertas.php?key=...

$COINS   = ['BTC','ETH','SOL','XRP','ADA'];
$TFS     = ['4h'=>'4H','1d'=>'1D','1w'=>'1S','1M'=>'1M'];
$CAPITAL = 1000;   // USD
$RISK    = 1.0;    // % del capital que aceptas perder por trade
$MAX_LEV = 3;      // apalancamiento máximo

$MIN_SCORE = 60;   // fuerza mínima (0–100)
$MIN_WIN   = 0.55; // acierto histórico mínimo
$MIN_N     = 8;    // mínimo de casos históricos

// ── 2. NO HACE FALTA TOCAR NADA ABAJO ──
$SL_ATR = 1.5; $TP1_ATR = 2; $TP2_ATR = 3.5; $LOOK = 30;
$HIGHER = ['4h'=>'1d','1d'=>'1w','1w'=>'1M'];
$STATE  = __DIR__ . '/quantum-state.json';

if (php_sapi_name() !== 'cli' && ($_GET['key'] ?? '') !== $SECRET) { http_response_code(403); exit('Acceso denegado'); }
header('Content-Type: text/plain; charset=utf-8');

function http_get($url){
  if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>15,CURLOPT_USERAGENT=>'QUANTUM/1.0']);
    $r = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return $code===200 ? $r : false;
  }
  return @file_get_contents($url, false, stream_context_create(['http'=>['timeout'=>15]]));
}
function tg($text){
  global $BOT_TOKEN,$CHAT_ID;
  $url = "https://api.telegram.org/bot$BOT_TOKEN/sendMessage";
  $data = http_build_query(['chat_id'=>$CHAT_ID,'text'=>$text,'parse_mode'=>'HTML','disable_web_page_preview'=>'true']);
  if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$data,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>15]);
    $r = curl_exec($ch); curl_close($ch);
  } else {
    $r = @file_get_contents($url,false,stream_context_create(['http'=>['method'=>'POST','header'=>'Content-Type: application/x-www-form-urlencoded','content'=>$data,'timeout'=>15]]));
  }
  $j = json_decode((string)$r,true);
  return !empty($j['ok']);
}
function klines($sym,$tf){
  foreach (['https://api.binance.com','https://data-api.binance.vision'] as $h) {
    $r = http_get("$h/api/v3/klines?symbol={$sym}USDT&interval=$tf&limit=1000");
    $j = $r ? json_decode($r,true) : null;
    if (is_array($j) && count($j) > 40) {
      $D = ['t'=>[],'o'=>[],'h'=>[],'l'=>[],'c'=>[]];
      foreach ($j as $k){ $D['t'][]=(float)$k[0]; $D['o'][]=(float)$k[1]; $D['h'][]=(float)$k[2]; $D['l'][]=(float)$k[3]; $D['c'][]=(float)$k[4]; }
      return $D;
    }
  }
  return null;
}

// ── Indicadores ──
function ema($a,$p){ $n=count($a); $o=array_fill(0,$n,NAN); if($n<$p) return $o; $e=array_sum(array_slice($a,0,$p))/$p; $o[$p-1]=$e; $k=2/($p+1); for($i=$p;$i<$n;$i++){ $e=$a[$i]*$k+$e*(1-$k); $o[$i]=$e; } return $o; }
function rsi($c,$p=14){ $n=count($c); $o=array_fill(0,$n,NAN); if($n<=$p) return $o; $g=0;$l=0; for($i=1;$i<=$p;$i++){ $d=$c[$i]-$c[$i-1]; if($d>0)$g+=$d; else $l-=$d; } $g/=$p;$l/=$p; $o[$p]=$l?100-100/(1+$g/$l):100;
  for($i=$p+1;$i<$n;$i++){ $d=$c[$i]-$c[$i-1]; $g=($g*($p-1)+max($d,0))/$p; $l=($l*($p-1)+max(-$d,0))/$p; $o[$i]=$l?100-100/(1+$g/$l):100; } return $o; }
function macdH($c){ $f=ema($c,12); $s=ema($c,26); $n=count($c); $m=[]; for($i=0;$i<$n;$i++) $m[$i]=$f[$i]-$s[$i]; $st=-1; for($i=0;$i<$n;$i++) if(is_finite($m[$i])){ $st=$i; break; }
  $o=array_fill(0,$n,NAN); if($st<0) return $o; $sg=ema(array_slice($m,$st),9); for($i=$st;$i<$n;$i++) $o[$i]=$m[$i]-$sg[$i-$st]; return $o; }
function atr($h,$l,$c,$p=14){ $n=count($c); $o=array_fill(0,$n,NAN); if($n<=$p) return $o; $s=0; for($i=1;$i<=$p;$i++) $s+=max($h[$i]-$l[$i],abs($h[$i]-$c[$i-1]),abs($l[$i]-$c[$i-1])); $a=$s/$p; $o[$p]=$a;
  for($i=$p+1;$i<$n;$i++){ $a=($a*($p-1)+max($h[$i]-$l[$i],abs($h[$i]-$c[$i-1]),abs($l[$i]-$c[$i-1])))/$p; $o[$i]=$a; } return $o; }

// ── Motor de señal (idéntico al HTML) ──
function score($I,$c,$i){
  foreach ([$I['e20'][$i],$I['e50'][$i],$I['r'][$i],$I['h'][$i],$I['h'][$i-1],$I['a'][$i]] as $v) if(!is_finite($v)) return null;
  $s=0; $k=1;
  $s += $I['e20'][$i]>$I['e50'][$i] ? 30 : -30;
  $s += $c[$i]>$I['e50'][$i] ? 15 : -15;
  if (is_finite($I['e200'][$i])) $s += $c[$i]>$I['e200'][$i] ? 15 : -15; else $k=100/85;
  $s += $I['h'][$i]>0 ? 15 : -15;
  $s += $I['h'][$i]>$I['h'][$i-1] ? 10 : -10;
  $r=$I['r'][$i];
  if ($r<30) $s+=15; elseif ($r>70) $s-=15; else $s+=max(-10,min(10,($r-50)/2));
  return (int)round(max(-100,min(100,$s*$k)));
}
function sigOf($s){ return $s>=50?1:($s<=-50?-1:0); }
function analyze($D){
  global $SL_ATR,$TP1_ATR,$TP2_ATR,$LOOK;
  $c=$D['c']; $n=count($c);
  $I=['e20'=>ema($c,20),'e50'=>ema($c,50),'e200'=>ema($c,200),'r'=>rsi($c),'h'=>macdH($c),'a'=>atr($D['h'],$D['l'],$c)];
  $i=$n-2; $s=score($I,$c,$i); if($s===null) return null;
  $sg=sigOf($s); $dir=$sg?:($s>=0?1:-1);
  // acierto histórico
  $W=[1=>0,-1=>0]; $N=[1=>0,-1=>0]; $prev=0;
  for($j=30;$j<$n-2;$j++){
    $sc=score($I,$c,$j); $g=$sc===null?0:sigOf($sc);
    if($g && $g!==$prev){
      $e=$D['o'][$j+1]; $A=$I['a'][$j]; $sl=$e-$g*$SL_ATR*$A; $tp=$e+$g*$TP1_ATR*$A; $out=null;
      for($k=$j+1;$k<min($n-1,$j+1+$LOOK);$k++){
        $hs=$g>0?$D['l'][$k]<=$sl:$D['h'][$k]>=$sl; $ht=$g>0?$D['h'][$k]>=$tp:$D['l'][$k]<=$tp;
        if($hs){$out=0;break;} if($ht){$out=1;break;}
      }
      if($out!==null){ $N[$g]++; $W[$g]+=$out; }
    }
    $prev=$g;
  }
  $px=$c[$n-1]; $A=$I['a'][$i];
  return ['sg'=>$sg,'dir'=>$dir,'s'=>$s,'px'=>$px,'barT'=>$D['t'][$i],
    'zoneA'=>$px-$dir*0.5*$A,'sl'=>$px-$dir*$SL_ATR*$A,'tp1'=>$px+$dir*$TP1_ATR*$A,'tp2'=>$px+$dir*$TP2_ATR*$A,
    'win'=>$N[$dir]?$W[$dir]/$N[$dir]:0,'n'=>$N[$dir]];
}
function strong($r,$up){
  global $MIN_SCORE,$MIN_WIN,$MIN_N;
  if(!$r||!$r['sg']||abs($r['s'])<$MIN_SCORE||$r['n']<$MIN_N||$r['win']<$MIN_WIN) return false;
  return !($up && $up['sg']===-$r['sg']);
}
function fp($v){ $a=abs($v); $d=$a>=1000?0:($a>=100?1:($a>=10?2:4)); return '$'.number_format($v,$d,'.',','); }
function fm($v){ return '$'.number_format(round($v),0,'.',','); }
function pc($v){ return ($v>0?'+':'').number_format($v*100,1).'%'; }

// ── Prueba ──
if (isset($_GET['test']) || in_array('--test',$argv ?? [])) {
  echo tg("✅ <b>QUANTUM conectado</b>\nEl robot de alertas está funcionando.") ? "Mensaje de prueba enviado\n" : "Error: revisa token y chat ID\n";
  exit;
}

// ── Ciclo principal ──
$state = is_file($STATE) ? (json_decode(file_get_contents($STATE),true) ?: []) : [];
$sent = 0;
foreach ($COINS as $coin) {
  $res = [];
  foreach ($TFS as $tf=>$lbl) { $D = klines($coin,$tf); $res[$tf] = $D ? analyze($D) : null; }
  foreach ($TFS as $tf=>$lbl) {
    $r = $res[$tf]; $up = isset($HIGHER[$tf]) ? $res[$HIGHER[$tf]] : null;
    $key = "$coin|$tf"; $tag = $r ? $r['sg'].'@'.$r['barT'] : '';
    echo str_pad("$coin $lbl",10).($r ? 'fuerza '.str_pad($r['s'],5).' acierto '.round($r['win']*100).'% n='.$r['n'] : 'sin datos');
    if (strong($r,$up) && ($state[$key] ?? '') !== $tag) {
      $dist = abs($r['px']-$r['sl'])/$r['px'];
      $notional = min($CAPITAL*$RISK/100/$dist, $CAPITAL*$MAX_LEV); $lev=$notional/$CAPITAL;
      $z1=min($r['zoneA'],$r['px']); $z2=max($r['zoneA'],$r['px']);
      $msg = ($r['sg']>0?'🟢 <b>COMPRAR':'🔴 <b>VENDER')." $coin · $lbl</b>\n"
        ."Precio: ".fp($r['px'])."\nZona: ".fp($z1)." – ".fp($z2)
        ."\nStop: ".fp($r['sl'])." (".pc(($r['sl']-$r['px'])/$r['px']).")"
        ."\nObjetivo 1: ".fp($r['tp1'])." · Objetivo 2: ".fp($r['tp2'])
        ."\n\nAcierto histórico: ".round($r['win']*100)."% (".$r['n']." señales)"
        ."\nFuerza: ".abs($r['s'])."/100".($up ? " · ".$TFS[$HIGHER[$tf]].": ".($up['sg']>0?'comprar':($up['sg']<0?'vender':'esperar')) : '')
        ."\nMáximo a meter: ".fm($notional).($lev>1?' ('.number_format($lev,1).'×)':'')." · arriesgas ".fm($notional*$dist);
      if (tg($msg)) { $state[$key]=$tag; $sent++; echo "  → ALERTA ENVIADA"; }
    }
    echo "\n";
  }
}
file_put_contents($STATE, json_encode($state));
echo "\nAlertas enviadas: $sent\n";
