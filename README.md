# Certify LMS

マルチ資格対応の資格学習プラットフォームです。受講生は資格ごとの教材で学習し、演習問題・模擬試験で理解度を確かめながら、コーチの面談サポートを受けて資格取得を目指せます。

> プロジェクト構造・ドメインモデル・コードの読み進め方は [ONBOARDING.md](./ONBOARDING.md) を参照してください。

## 主な機能

| ロール | 機能 |
|---|---|
| 受講生（student） | 教材閲覧 / 演習問題・苦手分野ドリル / 模擬試験（分野別ヒートマップ・合格可能性スコア）/ 面談予約 / チャット / AI 相談（Gemini） / 学習時間・進捗・ストリーク管理 / 修了証の受領 |
| コーチ（coach） | 教材・演習問題・模試の管理 / 担当受講生の進捗フォロー / 面談対応・面談メモ / チャット |
| 管理者（admin） | ユーザー招待・管理 / 資格・資格分類マスタ管理 / 資格へのコーチ割当 / 面談回数の付与 / 全体ダッシュボード |

## 動作環境

- Docker Desktop / Docker Compose
- 開発環境は Laravel Sail で構築します（PHP コンテナ・MySQL・Mailpit・phpMyAdmin を起動）

## 環境構築手順

### 1. リポジトリの clone

```bash
git clone <このリポジトリの URL>
cd <リポジトリ名>
```

### 2. 環境変数ファイルの作成

```bash
cp .env.example .env
```

`.env.example` は Sail 向けに設定済みのため、コピーするだけでローカル開発を始められます（外部サービス連携のキーは後述）。

### 3. 依存パッケージのインストール（初回のみ）

`vendor/` がまだ無いため、初回のみ Docker 経由で Composer を実行します。

```bash
docker run --rm \
    -u "$(id -u):$(id -g)" \
    -v "$(pwd):/var/www/html" \
    -w /var/www/html \
    laravelsail/php84-composer:latest \
    composer install --ignore-platform-reqs
```

### 4. Sail エイリアスの設定（推奨）

```bash
alias sail='./vendor/bin/sail'
```

以降のコマンドはこのエイリアス前提で記載します（未設定の場合は `./vendor/bin/sail` に読み替えてください）。

### 5. コンテナの起動

```bash
sail up -d
```

### 6. アプリケーションの初期化

```bash
sail artisan key:generate
sail artisan storage:link
sail artisan migrate:fresh --seed
```

`storage:link` は教材画像・プロフィール画像の配信に必要です。`migrate:fresh --seed` でテーブル作成とデモデータ投入が行われます（いつでも再実行してデータを初期状態に戻せます）。

### 7. フロントエンドのビルド

```bash
sail npm install
sail npm run build
```

Blade / CSS / JS を編集しながら開発する場合は、`build` の代わりに `sail npm run dev` を起動したままにしてください（Vite のホットリロードが効きます）。

### 8. 動作確認

http://localhost:8000 にアクセスし、下記の[ログインアカウント](#ログインアカウント)でログインできればセットアップ完了です。

## 開発環境 URL

| 用途 | URL |
|---|---|
| アプリケーション | http://localhost:8000 |
| phpMyAdmin（DB 確認） | http://localhost:8080 |
| Mailpit（メール確認） | http://localhost:8025 |

アプリケーションが送信するメール（招待メールなど）はすべて Mailpit に届きます。実際のメールは送信されません。

## ログインアカウント

`migrate:fresh --seed` 後、以下の固定アカウントが使えます（パスワードはすべて `password`）。

| ロール | メールアドレス | 備考 |
|---|---|---|
| 管理者 | admin@certify-lms.test | 全機能にアクセス可能 |
| コーチ | coach@certify-lms.test | IT 系資格の担当 |
| コーチ | coach2@certify-lms.test | ビジネス系資格の担当 |
| 受講生 | student@certify-lms.test | 受講中の資格・学習履歴・面談などのデモデータ付き |

このほか、ライフサイクル（招待中 / 受講中 / 卒業 / 退会）を網羅したデモユーザーが投入されます。

> 本サービスは**招待制**です。公開の会員登録画面はありません。新規ユーザーを作るには、管理者でログイン → ユーザー管理から招待 → Mailpit で招待メールの URL を開く → オンボーディング登録、という流れになります。

## テスト

```bash
sail artisan test                  # 全テスト実行
sail artisan test --filter=Xxx    # クラス名・メソッド名で絞り込み
```

## コード整形

Laravel Pint を使用しています。コミット前に実行してください。

```bash
sail bin pint --dirty    # 変更ファイルのみ整形
sail bin pint --test     # 整形漏れの確認（CI 相当のチェック）
```

## 使用技術

- PHP 8.5 / Laravel 10
- MySQL 8.4
- Laravel Fortify（認証）/ Laravel Sanctum（API 認証）
- Blade + Tailwind CSS + Vite（JavaScript は素の JS、フレームワーク不使用）
- PHPUnit / Laravel Pint
- league/commonmark（教材本文の Markdown レンダリング）
- Pusher（チャットのリアルタイム配信）
- Docker（Laravel Sail）

## 環境変数

`.env.example` をコピーするだけで、すべての機能がローカルで動作します（メールは Mailpit に配信されます）。

- `PUSHER_*` — チャットのリアルタイム配信に使用します。有効にする場合は Pusher のキーを取得して設定し、`BROADCAST_DRIVER=pusher` に変更してください。未設定（既定の `BROADCAST_DRIVER=log`）でもメッセージの送受信自体は動作し、相手画面へのリアルタイム反映のみ行われません
- `GEMINI_API_KEY` — AI 相談（受講生向け AI チャットボット、Gemini 連携）機能で使用します。
  未設定でもアプリは正常に起動し、既存機能はすべて動作します。AI 相談画面自体は表示されますが、
  「現在ご利用いただけません」という案内が表示され、メッセージ送信はできません。
  - 取得手順: [Google AI Studio](https://aistudio.google.com/apikey) に Google アカウントでログインし、
    「Create API key」から発行してください（無料枠あり）。発行したキーを `GEMINI_API_KEY` に設定します。
  - `AI_CHAT_ENABLED` — AI 相談機能全体の ON/OFF スイッチです。`false` にすると画面・ルートごと
    利用できなくなります（既定 `true`）。
  - `GEMINI_MODEL` / `GEMINI_BASE_URL` / `GEMINI_TIMEOUT` / `GEMINI_RETRY_TIMES` / `GEMINI_RETRY_DELAY_MS` —
    Gemini API との通信設定です。通常は既定値のままで問題ありません。
  - `GEMINI_MAX_TOTAL_WAIT_SECONDS` — 通信が全滅した場合でも合計でここまでしか待たない上限です
    （既定 45 秒）。`GEMINI_TIMEOUT` × 試行回数の合計がこれを超えたら以後の再試行を打ち切り、
    受講生のリクエストが長時間ブロックされ続けないようにします。
  - `AI_CHAT_DAILY_MESSAGE_LIMIT` — 受講生 1 人・1 日あたりのメッセージ送信上限です（既定 30）。
  - `AI_CHAT_HISTORY_LIMIT` — AI への入力に引き継ぐ直近メッセージ件数です（既定 20）。
  - `AI_CHAT_AUTO_TITLE_ENABLED` — 初回 AI 応答後に会話タイトルを AI が自動生成する機能の ON/OFF です
    （既定 `true`）。受講生が手動で編集した会話タイトルは上書きしません。
  - `AI_CHAT_SYSTEM_PROMPT_BASE` / `AI_CHAT_SYSTEM_PROMPT_SECTION_CONTEXT` /
    `AI_CHAT_SYSTEM_PROMPT_CERTIFICATION_CONTEXT` — Gemini へ渡すシステムプロンプト(AI への指示文)です。
    管理画面 / DB での管理は仕様のスコープ外のため、環境変数のみで完結します。
    `:title`(教材名)/ `:name`(資格名)はそれぞれ動的に置換されるプレースホルダです。

- `DASHBOARD_ADMIN_CACHE_TTL` — 管理者ダッシュボードの集計（全体 KPI / 資格別修了率）をキャッシュする秒数です。既定値 300 秒（5 分）で、未設定でも動作します。受講登録の状態遷移・受講解除時は TTL を待たずに即時無効化されます

- `GOOGLE_CALENDAR_CLIENT_ID` / `GOOGLE_CALENDAR_CLIENT_SECRET` — コーチの Google カレンダー連携（設定画面の面談設定タブ）に使用します。未設定でもアプリは正常に起動し、面談の予約 / キャンセル / 空き枠表示など既存機能はすべて従来通り動作します（連携ボタンを押した際に Google 側の認可画面へ遷移できないだけです）。取得手順は以下の通りです。
  1. [Google Cloud Console](https://console.cloud.google.com/) でプロジェクトを作成（または既存プロジェクトを選択）する
  2. 「API とサービス」→「ライブラリ」から **Google Calendar API** を有効化する
  3. 「API とサービス」→「OAuth 同意画面」を設定する（テスト目的であれば「External」+ テストユーザー登録で可）
  4. 「API とサービス」→「認証情報」→「認証情報を作成」→「OAuth クライアント ID」を選択し、アプリケーションの種類は「ウェブ アプリケーション」を選ぶ
  5. 「承認済みのリダイレクト URI」に `{APP_URL}/settings/google-calendar/callback`（ローカルなら `http://localhost:8000/settings/google-calendar/callback`）を登録する
  6. 発行されたクライアント ID / クライアントシークレットをそれぞれ `GOOGLE_CALENDAR_CLIENT_ID` / `GOOGLE_CALENDAR_CLIENT_SECRET` に設定する
- `GOOGLE_CALENDAR_CONNECT_TIMEOUT` / `GOOGLE_CALENDAR_TIMEOUT` — Google Calendar API への接続 / 応答タイムアウト（秒、小数可）。未設定時は既定値の 5 秒 / 10 秒。Google 側が無応答のままだと空き枠表示・予約・キャンセルがこの秒数まで待たされてからフォールバックするため、環境に応じて調整してください。

  > **本番運用時の注意**: 現状、Google の OAuth トークン（`google_calendar_credentials` テーブルの `access_token` / `refresh_token`）は平文で保存しています（本チケットのスコープ外）。本番運用する場合は、Laravel の暗号化キャスト（[`encrypted` cast](https://laravel.com/docs/10.x/eloquent-mutators#encrypted-casting)）等を用いた暗号化保存を別途検討してください。また、連携解除（設定画面の「連携を解除する」ボタン）は LMS 側のレコードを削除するのみで、発行済みのトークンを Google 側で失効させる処理は行っていません（本チケットのスコープ外）。Google 側での失効も必要な場合は、解除時に [OAuth 2.0 のトークン取り消しエンドポイント](https://developers.google.com/identity/protocols/oauth2/web-server#tokenrevoke)を呼び出す実装を別途検討してください。

新しい環境変数やセットアップ手順を追加した場合は、`.env.example` と本 README に追記し、チームの誰でも環境を再現できる状態を保ってください。
