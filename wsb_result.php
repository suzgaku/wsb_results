<?php
// wsb_result.php  ? user ごとに 1 ファイルへ集約（type を列で持つ）
// 既存CSV:  "0,PCNAME,4,20250918153022"
// 新JSONL:  {"type":0,"item":"PCNAME","status":4,"ts":20250918153022}
date_default_timezone_set('Asia/Tokyo');

define('DATA_DIR', '/var/lib/wsblog');  // 既存と同じ

// ---------- ユーティリティ ----------
function json_out($arr, $code = 200) {
  header('Content-Type: application/json; charset=UTF-8');
  if (function_exists('http_response_code')) http_response_code($code);
  echo json_encode($arr, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}
function dir_is_writable($path){
  if (!is_dir($path)) return false;
  $test = rtrim($path, '/\\') . '/.__perm_test_' . mt_rand(1000,9999);
  $fh = @fopen($test, 'wb'); if (!$fh) return false;
  $ok = @fwrite($fh, "t") !== false; @fclose($fh); @unlink($test); return $ok;
}
function atomic_write($file, $buf, &$err){
  $err = '';
  $tmp = $file . '.tmp.' . mt_rand(1000,9999);
  $fh = @fopen($tmp, 'wb'); if (!$fh){ $err='tmp open failed'; return false; }
  $need = strlen($buf);
  if ($need>0){ $w=@fwrite($fh,$buf); if ($w===false || $w<$need){ @fclose($fh); @unlink($tmp); $err='tmp write failed'; return false; } }
  if (!@fflush($fh)){ @fclose($fh); @unlink($tmp); $err='tmp flush failed'; return false; }
  @fclose($fh);
  if (is_file($file)) @unlink($file);
  if (!@rename($tmp,$file)){ @unlink($tmp); $last=error_get_last(); $err='rename failed'.($last['message']??''); return false; }
  return true;
}
function data_file_for_user($user){
  return DATA_DIR . "/wsb_result_data_{$user}.dat";
}
function lock_file_for_user($user){
  return DATA_DIR . "/wsb_result_data_{$user}.lock";
}
function load_config_frames($user){
  $cfg = array();
  $f = DATA_DIR . "/wsb_result_config_{$user}.dat";
  if (is_file($f)) {
    $raw = @file_get_contents($f);
    $dec = @json_decode($raw, true);
    if (is_array($dec)) $cfg = $dec;
  }
  return $cfg; // [ {title, ok_values[], ignore_values[], item_label, result_labels:{ok,ng}} ... ]
}

// ---------- 入力 ----------
$mode   = isset($_GET['mode']) ? $_GET['mode'] : 'html';
$pc     = isset($_GET['pc']) ? trim($_GET['pc']) : '';
$status = isset($_GET['status']) ? $_GET['status'] : null;
$user   = isset($_GET['user']) ? $_GET['user'] : null;
$type   = isset($_GET['type']) ? $_GET['type'] : null;

// ---------- diag ----------
if ($mode === 'diag') {
  json_out(array('diag'=>array(
    'php_version'=>PHP_VERSION,
    'sapi'=>php_sapi_name(),
    'data_dir'=>DATA_DIR,
    'data_dir_exists'=>is_dir(DATA_DIR),
    'data_dir_writable'=>dir_is_writable(DATA_DIR),
  )));
  exit;
}

// ---------- json (記録API) ----------
if ($mode === 'json') {
  // user/type 必須
  if ($user === null || !preg_match('/^\d+$/', (string)$user)) { json_out(array('error'=>"invalid 'user'"),400); exit; }
  if ($type === null || !preg_match('/^\d+$/', (string)$type)) { json_out(array('error'=>"invalid 'type'"),400); exit; }

  header('Content-Type: application/json; charset=UTF-8');

  if ($pc === '' || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $pc)) { json_out(array('error'=>"invalid 'pc'"),400); exit; }
  if ($status !== null && !preg_match('/^-?\d+$/', (string)$status)) { json_out(array('error'=>"invalid 'status'"),400); exit; }

  $status = ($status===null) ? null : (int)$status;
  $type   = (int)$type;
  $nowNum = (int)date('YmdHis');

  if (!is_dir(DATA_DIR) || !dir_is_writable(DATA_DIR)) { json_out(array('error'=>'data_dir not writable','dir'=>DATA_DIR),500); exit; }

  // 枠ごとの ignore_values を参照
  $frames = load_config_frames($user);
  $ignore = array();
  if (isset($frames[$type]) && is_array($frames[$type])) {
    $ig = isset($frames[$type]['ignore_values']) ? $frames[$type]['ignore_values'] : array();
    if (is_array($ig)) $ignore = array_map('intval', array_filter($ig, 'is_numeric'));
  }
  if ($status !== null && in_array($status, $ignore, true)) {
    json_out(array('result'=>'ignored','pc'=>$pc,'status'=>$status,'user'=>$user,'type'=>$type));
    exit;
  }

  $file = data_file_for_user($user);
  $lock = lock_file_for_user($user);
  $lockfh = @fopen($lock, 'c');
  if (!$lockfh) { json_out(array('error'=>'cannot open lock'),500); exit; }
  if (!@flock($lockfh, LOCK_EX)) { @fclose($lockfh); json_out(array('error'=>'cannot lock'),500); exit; }

  // 現在ファイルを読み込み → (type,pc) 単位で最新のみ保持する map を作る
  $map = array(); // key: "{$type}|{$pc}" => [status, ts]
  if (is_file($file) && is_readable($file)) {
    $lines = @file($file, FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);
    if ($lines) {
      foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') continue;

        // 新フォーマット(JSONL)
        if ($line[0] === '{') {
          $obj = json_decode($line, true);
          if (is_array($obj) && isset($obj['type'],$obj['item'],$obj['status'],$obj['ts'])) {
            $t  = (int)$obj['type'];
            $ik = (string)$obj['item'];
            $st = (int)$obj['status'];
            $ts = (int)$obj['ts'];
            $map[$t.'|'.$ik] = array($st,$ts);
            continue;
          }
        }

        // 旧フォーマット(CSV)
        $p = explode(',', $line);
        if (count($p) >= 4) {
          $t  = (int)$p[0];
          $ik = $p[1];
          $st = (int)$p[2];
          $ts = (int)$p[3];
          $map[$t.'|'.$ik] = array($st,$ts);
        }
      }
    }
  }

  if ($status !== null) {
    $map[$type.'|'.$pc] = array($status, $nowNum);
  }

  // JSON Lines で書き戻し（旧CSVもここで移行）
  ksort($map, SORT_STRING);
  $buf = '';
  foreach ($map as $k => $arr) {
    list($tStr, $pcKey) = explode('|', $k, 2);
    $rec = array(
      'type'   => (int)$tStr,
      'item'   => (string)$pcKey,
      'status' => (int)$arr[0],
      'ts'     => (int)$arr[1],
    );
    $buf .= json_encode($rec, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) . "\n";
  }
  $err=''; $ok = atomic_write($file, $buf, $err);

  @flock($lockfh, LOCK_UN); @fclose($lockfh);
  if (!$ok) { json_out(array('error'=>'atomic write failed','detail'=>$err),500); exit; }

  json_out(array('result'=>'ok','pc'=>$pc,'status'=>$status,'user'=>$user,'type'=>$type,'timestamp_num'=>$nowNum));
  exit;
}

// ---------- HTML 表示 ----------
if ($user === null || !preg_match('/^\d+$/', (string)$user)) {
  header('Content-Type: text/plain; charset=UTF-8'); echo "Use ?user=123 (optional &type=0)"; exit;
}

$frames = load_config_frames($user); // [ {title, ok_values[], ignore_values[], item_label, result_labels:{ok,ng}} ... ]
$types = array();
if ($type !== null && preg_match('/^\d+$/', (string)$type)) {
  $types = array((int)$type);
} else {
  $types = (count($frames) > 0) ? range(0, count($frames)-1) : array(0);
}

// ファイルを一度だけロード → typeごとに行を振り分け
$file = data_file_for_user($user);
$rows_by_type = array(); // type => [ rows... ]
foreach ($types as $t) { $rows_by_type[$t] = array(); }

if (is_file($file) && is_readable($file)) {
  $lines = @file($file, FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);
  if ($lines) {
    foreach ($lines as $line) {
      $line = trim($line);
      if ($line === '') continue;

      $t = null; $pcKey=''; $st=null; $ts=null;

      // JSONL優先
      if ($line[0] === '{') {
        $obj = json_decode($line, true);
        if (is_array($obj) && isset($obj['type'],$obj['item'],$obj['status'],$obj['ts'])) {
          $t  = (int)$obj['type'];
          $pcKey = (string)$obj['item'];
          $st = (int)$obj['status'];
          $ts = (int)$obj['ts'];
        }
      } else {
        // 旧CSV
        $p = explode(',', $line);
        if (count($p) >= 4) {
          $t  = (int)$p[0];
          $pcKey = $p[1];
          $st = (int)$p[2];
          $ts = (int)$p[3];
        }
      }

      if ($t === null) continue;
      if (!in_array($t, $types, true)) continue;

      // 枠設定
      $cfg = isset($frames[$t]) ? $frames[$t] : array();

      // ignore
      $ignore_values = array();
      if (isset($cfg['ignore_values']) && is_array($cfg['ignore_values'])) {
        $ignore_values = array_map('intval', array_filter($cfg['ignore_values'],'is_numeric'));
      }
      if ($st !== null && in_array($st, $ignore_values, true)) continue;

      // ok 判定
      $ok_values = array();
      if (isset($cfg['ok_values']) && is_array($cfg['ok_values'])) {
        $ok_values = array_map('intval', array_filter($cfg['ok_values'],'is_numeric'));
      }
      $isOk = ($st !== null) && in_array($st, $ok_values, true);

      // ラベル（②で自由化）
      $labelOk = isset($cfg['result_labels']['ok']) ? (string)$cfg['result_labels']['ok'] : 'OK';
      $labelNg = isset($cfg['result_labels']['ng']) ? (string)$cfg['result_labels']['ng'] : 'NG';
      $resultStr = $isOk ? $labelOk : $labelNg;
      $cssClass  = $isOk ? 'ok' : 'ng';

      // 日時
      $dtStr = '';
      if (preg_match('/^(\d{4})(\d{2})(\d{2})(\d{2})(\d{2})\d{2}$/', (string)$ts, $m)) {
        $dtStr = sprintf('%04d/%02d/%02d %02d:%02d', $m[1], $m[2], $m[3], $m[4], $m[5]);
      }

      $rows_by_type[$t][] = array($pcKey, $resultStr, $st, $dtStr, $cssClass);
    }
  }
}

// 表示セクションを構築
$sections = array();
foreach ($types as $t) {
  $cfg = isset($frames[$t]) && is_array($frames[$t]) ? $frames[$t] : array();
  $title = isset($cfg['title']) && trim($cfg['title']) !== '' ? trim($cfg['title']) : 'Windows Server Backup Result';
  $itemLabel = isset($cfg['item_label']) && trim($cfg['item_label']) !== '' ? trim($cfg['item_label']) : 'PC';
  $sections[] = array('type'=>$t, 'title'=>$title, 'item_label'=>$itemLabel, 'rows'=>$rows_by_type[$t]);
}
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>WSB Results</title>
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<style>
  body { font-family: system-ui, sans-serif; margin: 16px; }
  h2 { margin: 1rem 0 .5rem; }
  table {border-collapse: collapse; background-color: #CDEFFF; margin-bottom: 1.25rem;}
  th {background-color: #2CACE8;}
  th,td {border: solid 1px; padding: 4px 8px;}
  .ok  {background: #e6ffe6;}
  .ng  {background: #ffe6e6;}
  .typeBadge { font-size:20%; color:#333; background:#eef; border:1px solid #99b; border-radius:6px; padding:2px 6px; margin-left:.5rem; }
</style>
</head>
<body>
<p><b>WSB Results</b></p>

  <?php foreach ($sections as $sec): ?>
    <h2>
      <span class="typeBadge">type=<?php echo (int)$sec['type']; ?></span><br>
      <?php echo htmlspecialchars($sec['title'], ENT_QUOTES, 'UTF-8'); ?>
    </h2>
    <table>
      <tr><th><?php echo htmlspecialchars($sec['item_label'], ENT_QUOTES, 'UTF-8'); ?></th><th>Result</th><th>code#</th><th>Date/Time</th></tr>
      <?php if (count($sec['rows']) === 0): ?>
        <tr><td colspan="4" style="text-align:center; background:#f9ffff;">(no data)</td></tr>
      <?php else: ?>
        <?php foreach ($sec['rows'] as $r): ?>
          <tr class="<?php echo htmlspecialchars($r[4], ENT_QUOTES, 'UTF-8'); ?>">
            <td><?php echo htmlspecialchars($r[0], ENT_QUOTES, 'UTF-8'); ?></td>
            <td><?php echo htmlspecialchars($r[1], ENT_QUOTES, 'UTF-8'); ?></td>
            <td><?php echo htmlspecialchars((string)$r[2], ENT_QUOTES, 'UTF-8'); ?></td>
            <td><?php echo htmlspecialchars($r[3], ENT_QUOTES, 'UTF-8'); ?></td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </table>
  <?php endforeach; ?>
<div style="margin-top:1.5rem; font-size:0.9em;">
  <a href="wsb_result_config.php?user=<?php echo htmlspecialchars($user, ENT_QUOTES, 'UTF-8'); ?>">
    設定ページへ
  </a>
</div>
</body>
</html>

