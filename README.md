# wsb_results

軽量なバックアップ／ヘルス集計ビューアです。  
クライアント（PC/サーバー）から結果コードをHTTPで送信し、サーバー側で集約・可視化します。

## 特徴
- 1列目の見出し（**Item**）を枠（type）ごとに自由設定（デフォルト: `PC`）
- 2列目の表示ラベル（**Result**）の **OK/NG 表記を自由化**（例: `SUCCESS` / `FAIL`）
- データ保存は **JSON Lines**（例: `{"type":0,"item":"HOST","status":4,"ts":20250918153022}`）
  - 既存の **CSV**（`type,item,status,ts`）も読み取り互換あり（次回書き込みでJSONLへ移行）
- 依存なし（標準PHPのみ）。表示と記録APIを **1ファイル**で提供（設定UIは別ファイル）

## 構成
- `wsb_result.php` … 記録API（`?mode=json`）＋ 表示（デフォルト `?mode=html`）
- `wsb_result_config.php` … 枠（frame/type）ごとの設定UI

## クイックスタート
1. 2ファイルをWebルートに配置
2. データ保存ディレクトリを作成（デフォルト `/var/lib/wsblog`）
   ```bash
   sudo mkdir -p /var/lib/wsblog
   sudo chown www-data:www-data /var/lib/wsblog   # 環境に合わせて変更
   sudo chmod 775 /var/lib/wsblog
   ```
3. 設定UIを開く  
   ```
   http://<server>/wsb_result_config.php?user=123
   ```
   - **タイトル**: セクション見出し
   - **Item**: 一覧1列目の見出し（例: PC / Host / Node など）
   - **Result**: 2列目で表示するOK/NGの文字（例: OK/NG, SUCCESS/FAIL）
   - **OKの値**: OK判定とする数値コード（例: 0,4 など）
   - **無視する値**: 表示/集計から除外する数値コード
4. クライアントから結果送信（PowerShell例）
   ```powershell
   $u=123; $t=0; $pc=$env:COMPUTERNAME; $st=4
   Invoke-WebRequest "http://<server>/wsb_result.php?mode=json&user=$u&type=$t&pc=$pc&status=$st"
   ```
   - `type` は設定UIでの枠の並び順（0始まり）
   - `status` は整数（OK/NG判定は枠の `OKの値` で決定）
5. ダッシュボード表示
   ```
   http://<server>/wsb_result.php?user=123
   ```

## データ形式
- **JSONL（新規書き込み）**
  ```json
  {"type":0,"item":"HOST1","status":4,"ts":20250918153022}
  ```
- **CSV（旧フォーマット読取のみ）**  
  `type,item,status,ts`
  - 読み込まれた後、次の書き込み時にJSONLへ移行します。

## API
- `GET /wsb_result.php?mode=json&user=<id>&type=<n>&pc=<name>&status=<int>`
  - 正常: `{"result":"ok","pc":"HOST","status":4,"user":"123","type":0,"timestamp_num":20250918153022}`
  - 無視対象: `{"result":"ignored", ...}`（枠の *無視する値* に一致）
- `GET /wsb_result.php?mode=diag`  
  - 環境情報（PHPバージョン、データディレクトリの権限など）を返します。

## セキュリティ
- 社内ネットワーク／VPN 配下での利用推奨
- 必要に応じてリバースプロキシやBasic認証を追加
- `pc` は `[A-Za-z0-9_-]{1,64}` のバリデーション済み

## 今後の拡張候補
- 第3の表示ラベル（WARN）等の任意マッピング（status→label）
- CSV/JSON エクスポート
- 認証/認可の簡易フック

## ライセンス
MIT
