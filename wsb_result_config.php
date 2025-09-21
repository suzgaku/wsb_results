<?php
// wsb_result_config.php
$user = isset($_GET['user']) ? $_GET['user'] : '';
if (!preg_match('/^\d+$/', $user)) { http_response_code(400); echo "Invalid or missing 'user' parameter. Use ?user=123"; exit; }

$DATA_DIR  = '/var/lib/wsblog';
$DATA_FILE = $DATA_DIR . "/wsb_result_config_{$user}.dat";

$flash = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $frames = isset($_POST['frames']) && is_array($_POST['frames']) ? $_POST['frames'] : [];
    $clean = [];
    foreach ($frames as $frame) {
        // タイトル
        $title = isset($frame['title']) ? trim((string)$frame['title']) : '';

        // ① Item 見出し名（デフォルト PC）
        $item_label = isset($frame['item_label']) ? trim((string)$frame['item_label']) : '';
        if ($item_label === '') $item_label = 'PC';

        // ② Result 表示ラベル（OK/NG：デフォルト OK / NG）
        $rl_ok = isset($frame['result_labels']['ok']) ? trim((string)$frame['result_labels']['ok']) : '';
        $rl_ng = isset($frame['result_labels']['ng']) ? trim((string)$frame['result_labels']['ng']) : '';
        if ($rl_ok === '') $rl_ok = 'OK';
        if ($rl_ng === '') $rl_ng = 'NG';
        $result_labels = ['ok'=>$rl_ok, 'ng'=>$rl_ng];

        // 既存仕様：OK として扱うコード、および無視するコード
        $ok_values = [];
        if (!empty($frame['ok_values']) && is_array($frame['ok_values'])) {
            foreach ($frame['ok_values'] as $v) { $v = trim((string)$v); if ($v !== '' && is_numeric($v)) $ok_values[] = $v + 0; }
        }
        $ignore_values = [];
        if (!empty($frame['ignore_values']) && is_array($frame['ignore_values'])) {
            foreach ($frame['ignore_values'] as $v) { $v = trim((string)$v); if ($v !== '' && is_numeric($v)) $ignore_values[] = $v + 0; }
        }

        // 何も設定がない枠は無視
        if ($title === '' && !$ok_values && !$ignore_values && $item_label === 'PC' && $rl_ok === 'OK' && $rl_ng === 'NG') continue;

        $clean[] = [
          'title'         => $title,
          'item_label'    => $item_label,
          'result_labels' => $result_labels,
          'ok_values'     => array_values($ok_values),
          'ignore_values' => array_values($ignore_values),
        ];
    }
    if (!is_dir($DATA_DIR)) @mkdir($DATA_DIR, 0775, true);
    $ok = @file_put_contents($DATA_FILE, json_encode($clean, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT), LOCK_EX);
    $flash = ($ok===false) ? '保存に失敗しました。権限やパスを確認してください。' : '保存しました。';
}

$saved = [];
if (is_file($DATA_FILE)) {
    $raw = @file_get_contents($DATA_FILE);
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) $saved = $decoded;
}
$initial_json = json_encode($saved, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8" />
<title>WSB Result Config (user=<?php echo htmlspecialchars($user, ENT_QUOTES, 'UTF-8'); ?>)</title>
<style>
body { font-family: system-ui, sans-serif; margin: 24px; }
.wrapper { max-width: 880px; margin: 0 auto; }
.frame { border:1px solid #d0d7de; border-radius:14px; padding:16px 18px; margin:1rem 0; background:#fff; box-shadow:0 2px 8px rgba(0,0,0,.06); }
.section { margin:.75rem 0; }
.controls { margin:.5rem 0; display:flex; align-items:center; gap:.5rem; flex-wrap: wrap; }
.row { display:flex; gap:.5rem; align-items:center; margin:.25rem 0; }
.row input[type="text"], .row input[type="number"] { flex:1 1 auto; padding:.4rem .5rem; }
button { padding:.4rem .6rem; border-radius:8px; border:1px solid #c8cdd4; background:#f6f8fa; cursor:pointer; }
button:hover { background:#eef1f4; }
button.del { border-color:#e0b4b4; background:#fff4f4; }
button.del:hover { background:#ffe9e9; }
.flash { margin:.5rem 0; padding:.5rem .75rem; border-radius:8px; border:1px solid #c8cdd4; background:#f6f8fa; }
.scheduler { margin-top:.75rem; padding:.5rem .75rem; border-left:3px solid #8aa1b1; background:#f7fbff; border-radius:8px; }
.scheduler pre { margin:.25rem 0 0; white-space:pre-wrap; font-family:ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace; }
.labelpair { display:flex; gap:.5rem; align-items:center; flex-wrap: wrap; }
.labelpair input[type="text"] { width:12rem; }
@media (prefers-color-scheme: dark){
  body{ background:#0b0d10; color:#e6e6e6; }
  .frame{ background:#111418; border-color:#2a2f36; box-shadow:none; }
  button{ border-color:#38414a; background:#1a1f25; color:#e6e6e6; }
  button:hover{ background:#212830; }
  button.del{ border-color:#5a2c2c; background:#2a1616; }
  button.del:hover{ background:#3a1c1c; }
  .flash{ border-color:#38414a; background:#1a1f25; }
  .scheduler{ background:#121922; border-left-color:#3a556b; }
}
</style>
</head>
<body>
<div class="wrapper">
  <p><b>WSB Results Config / user=<?php echo htmlspecialchars($user, ENT_QUOTES, 'UTF-8'); ?></b></p>

  <?php if ($flash): ?><div class="flash"><?php echo htmlspecialchars($flash, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>

  <div class="controls"><button id="addFrameBtn" type="button">＋ 枠を追加</button></div>

  <form id="settingsForm" action="?user=<?php echo htmlspecialchars($user, ENT_QUOTES, 'UTF-8'); ?>" method="post">
    <div id="framesContainer"></div>
    <div class="section"><button type="submit"><strong>設定</strong></button></div>
  </form>
</div>

<script>
const initialData = <?php echo $initial_json ?: '[]'; ?>;
const userId = <?php echo json_encode($user); ?>;

let frameCount = 0;

function refreshSchedulerHints() {
  const frames = Array.from(document.querySelectorAll('#framesContainer .frame'));
  frames.forEach((frame, idx) => {
    const codeLine = `-NoProfile -NoLogo -ExecutionPolicy Bypass -WindowStyle Hidden -File "C:\\tools\\wsb_notify.ps1" -User ${userId} -Type ${idx}`;
    const pre = frame.querySelector('.scheduler pre');
    if (pre) pre.textContent = codeLine;
  });
}

function addFrame(prefill) {
  frameCount++;
  const framesContainer = document.getElementById('framesContainer');
  const frame = document.createElement('div');
  frame.className = 'frame';

  // タイトル
  const titleRow = document.createElement('div');
  titleRow.className = 'section';
  titleRow.innerHTML = `
    <label>タイトル：
      <input type="text" name="frames[${frameCount}][title]" placeholder="タイトルを入力">
    </label>
  `;
  frame.appendChild(titleRow);

  // ① Item（列名）
  const itemRow = document.createElement('div');
  itemRow.className = 'section';
  itemRow.innerHTML = `
    <div><strong>Item</strong></div>
    <div class="row">
      <input type="text" name="frames[${frameCount}][item_label]" value="PC" placeholder="1列目の見出し（例: PC / Host / Node など）">
    </div>
  `;
  frame.appendChild(itemRow);

  // ② Result 表示ラベル（OK/NG）
  const resultRow = document.createElement('div');
  resultRow.className = 'section';
  resultRow.innerHTML = `
    <div><strong>Result</strong></div>
    <div class="labelpair">
      <label>OK:
        <input type="text" name="frames[${frameCount}][result_labels][ok]" value="OK">
      </label>
      <label>NG:
        <input type="text" name="frames[${frameCount}][result_labels][ng]" value="NG">
      </label>
    </div>
  `;
  frame.appendChild(resultRow);

  // OK の値
  const okSection = document.createElement('div');
  okSection.className = 'section';
  okSection.innerHTML = `
    <div class="controls">
      <strong>OKの値</strong>
      <button type="button" class="addRowBtn">追加</button>
    </div>
    <div class="rows"></div>
  `;
  frame.appendChild(okSection);

  // 無視する値
  const ignoreSection = document.createElement('div');
  ignoreSection.className = 'section';
  ignoreSection.innerHTML = `
    <div class="controls">
      <strong>無視する値</strong>
      <button type="button" class="addRowBtn">追加</button>
    </div>
    <div class="rows"></div>
  `;
  frame.appendChild(ignoreSection);

  // 枠削除ボタン
  const delFrameBtn = document.createElement('button');
  delFrameBtn.type = 'button';
  delFrameBtn.className = 'del';
  delFrameBtn.textContent = '枠を削除';
  delFrameBtn.addEventListener('click', () => {
    frame.remove();
    refreshSchedulerHints();
  });
  frame.appendChild(delFrameBtn);

  // スケジューラ表示
  const sched = document.createElement('div');
  sched.className = 'scheduler';
  sched.innerHTML = `
    <div>イベントスケジューラに設定する値</div>
    <pre></pre>
  `;
  frame.appendChild(sched);

  framesContainer.appendChild(frame);

  // セクション初期化
  const okRows = okSection.querySelector('.rows');
  const igRows = ignoreSection.querySelector('.rows');
  initSection(okSection.querySelector('.addRowBtn'), okRows, `frames[${frameCount}][ok_values][]`);
  initSection(ignoreSection.querySelector('.addRowBtn'), igRows, `frames[${frameCount}][ignore_values][]`);

  // 値の復元
  if (prefill && typeof prefill === 'object') {
    if (prefill.title) titleRow.querySelector('input[type="text"]').value = prefill.title;

    const itemInput = itemRow.querySelector('input[type="text"]');
    itemInput.value = (prefill.item_label && String(prefill.item_label).trim() !== '') ? prefill.item_label : 'PC';

    const okLbl = (prefill.result_labels && prefill.result_labels.ok) ? String(prefill.result_labels.ok) : 'OK';
    const ngLbl = (prefill.result_labels && prefill.result_labels.ng) ? String(prefill.result_labels.ng) : 'NG';
    resultRow.querySelector('input[name^="frames"][name$="[result_labels][ok]"]').value = okLbl;
    resultRow.querySelector('input[name^="frames"][name$="[result_labels][ng]"]').value = ngLbl;

    if (Array.isArray(prefill.ok_values)) {
      okRows.innerHTML = '';
      prefill.ok_values.forEach(v => addRow(okRows, `frames[${frameCount}][ok_values][]`, v));
    }
    if (Array.isArray(prefill.ignore_values)) {
      igRows.innerHTML = '';
      prefill.ignore_values.forEach(v => addRow(igRows, `frames[${frameCount}][ignore_values][]`, v));
    }
  }

  refreshSchedulerHints();
}

function initSection(addBtn, container, inputName) {
  addBtn.addEventListener('click', () => addRow(container, inputName, ''));
  addRow(container, inputName, '');
}

function addRow(container, inputName, defaultValue) {
  const row = document.createElement('div');
  row.className = 'row';

  const input = document.createElement('input');
  input.type = 'number';
  input.name = inputName;
  input.placeholder = '数値';
  if (defaultValue !== undefined && defaultValue !== null && defaultValue !== '') {
    input.value = defaultValue;
  }

  const del = document.createElement('button');
  del.type = 'button';
  del.className = 'del';
  del.textContent = '削除';
  del.addEventListener('click', () => row.remove());

  row.append(input, del);
  container.appendChild(row);
}

document.getElementById('addFrameBtn').addEventListener('click', () => addFrame());

// 初期表示
if (Array.isArray(initialData) && initialData.length > 0) {
  initialData.forEach(f => addFrame(f));
} else {
  addFrame();
}

// 送信前の掃除
document.getElementById('settingsForm').addEventListener('submit', (e) => {
  Array.from(e.target.querySelectorAll('input[type="number"]'))
    .filter(inp => !inp.value.trim())
    .forEach(inp => inp.closest('.row')?.remove());

  Array.from(e.target.querySelectorAll('.frame')).forEach(frame => {
    const title = frame.querySelector('input[type="text"]')?.value.trim() || '';
    const nums = Array.from(frame.querySelectorAll('input[type="number"]')).filter(i => i.value.trim() !== '');
    // item_label と result_labels はデフォルトが入るので空でも残す
    if (title === '' && nums.length === 0) frame.remove();
  });
});
</script>
<div style="margin-top:1.5rem; font-size:0.9em;">
  <a href="wsb_result.php?user=<?php echo htmlspecialchars($user, ENT_QUOTES, 'UTF-8'); ?>">
    結果ページへ
  </a>
</div>
</body>
</html>

