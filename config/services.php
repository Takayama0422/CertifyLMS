<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Google Calendar (S-A-01 コーチ Google カレンダー連携)
    |--------------------------------------------------------------------------
    |
    | 未設定でもアプリは起動し、既存の面談機能(空き枠表示 / 予約 / キャンセル)は動作し続ける。
    | 実際に外部通信が発生するのは連携済コーチが存在し、かつ通信が必要になった瞬間のみ
    | (App\Services\GoogleCalendar\GoogleCalendarService が失敗を捕捉してフォールバックする)。
    | 取得手順は README の「環境変数」節を参照。
    |
    | connect_timeout / timeout(秒。小数可): Google 側が無応答のまま待たされ続けないための
    | 接続 / 応答タイムアウト(App\Services\GoogleCalendar\GoogleApiCalendarClient が使用)。
    | 例外を投げる失敗は GoogleCalendarService が catch してフォールバックするが、
    | タイムアウトが無いと「応答が返ってこない」失敗はこの仕組みで捕捉できず待たされ続けてしまう。
    |
    */
    'google' => [
        'client_id' => env('GOOGLE_CALENDAR_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CALENDAR_CLIENT_SECRET'),
        'connect_timeout' => (float) env('GOOGLE_CALENDAR_CONNECT_TIMEOUT', 5),
        'timeout' => (float) env('GOOGLE_CALENDAR_TIMEOUT', 10),
    ],

];
