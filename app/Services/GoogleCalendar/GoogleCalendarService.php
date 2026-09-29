<?php

declare(strict_types=1);

namespace App\Services\GoogleCalendar;

use App\Exceptions\GoogleCalendar\GoogleCalendarStateMismatchException;
use App\Models\GoogleCalendarCredential;
use App\Models\Meeting;
use App\Models\User;
use App\Services\GoogleCalendar\Contracts\GoogleCalendarClient;
use App\Services\GoogleCalendar\DataTransfer\GoogleCalendarEvent;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Throwable;

/**
 * コーチの Google カレンダー連携をまとめて扱う唯一の窓口(まとまった単位で呼べる操作群)。
 *
 * 空き枠集計 / 予約 / キャンセル / 設定画面のどこから呼んでも、実通信の失敗(未連携含む)は
 * ここで catch して空配列 or no-op にフォールバックする ―― 呼び出し側は Google 連携の有無や
 * 通信成否を一切気にせず使える(「Google との通信に失敗しても面談機能の根幹は止めない」の実装箇所)。
 *
 * OAuth の `state` はセッションに保存し、コールバックで一致検証する(なりすまし拒否)。
 * トークンの有効期限が切れていれば `refresh_token` を使って自動更新し、連携を継続させる。
 */
final class GoogleCalendarService
{
    private const SESSION_STATE_KEY = 'google_calendar.oauth_state';

    private const SESSION_USER_KEY = 'google_calendar.oauth_user_id';

    private const SESSION_REDIRECT_KEY = 'google_calendar.oauth_redirect_path';

    private const PRIMARY_CALENDAR_ID = 'primary';

    public function __construct(
        private readonly GoogleCalendarClient $client,
    ) {}

    /**
     * 認可 URL を生成し、CSRF 検証用の state と遷移元をセッションへ保存する。
     */
    public function authorizationUrl(User $coach, string $redirectUri, string $redirectPath): string
    {
        $state = Str::random(40);

        Session::put(self::SESSION_STATE_KEY, $state);
        Session::put(self::SESSION_USER_KEY, $coach->id);
        Session::put(self::SESSION_REDIRECT_KEY, $redirectPath);

        return $this->client->authorizationUrl($state, $redirectUri);
    }

    /**
     * コールバックの `state` が、認可 URL 発行時にセッションへ保存した値(+ 発行元コーチ本人)と一致するかを
     * 判定する(セッションを変更しない非破壊の確認)。
     *
     * なりすまし検証は、セッションを変更する前(`consumeRedirectPath` 等)に必ずこちらで先に行うこと。
     * 検証前にセッションを変更すると、偽のコールバックが実際の連携フローを成立させられなくても、
     * 進行中の正規フローの状態(遷移先など)だけを破壊できてしまう。
     */
    public function stateMatchesSession(User $coach, string $state): bool
    {
        $expectedState = Session::get(self::SESSION_STATE_KEY);
        $expectedUserId = Session::get(self::SESSION_USER_KEY);

        return is_string($expectedState)
            && $expectedState !== ''
            && hash_equals($expectedState, $state)
            && $expectedUserId === $coach->id;
    }

    /**
     * コールバックを検証し(なりすまし拒否)、認可コードをトークンへ交換して連携情報を保存する。
     *
     * @throws GoogleCalendarStateMismatchException state が一致しない、またはセッションに紐づくコーチ本人でない場合
     */
    public function connect(User $coach, string $code, string $state, string $redirectUri): GoogleCalendarCredential
    {
        $isValid = $this->stateMatchesSession($coach, $state);

        // state の一致 / 不一致に関わらず 1 回使い切りにする(forget はここで行う: 判定結果はすでに確定済み)
        Session::forget([self::SESSION_STATE_KEY, self::SESSION_USER_KEY]);

        if (! $isValid) {
            throw new GoogleCalendarStateMismatchException;
        }

        $token = $this->client->exchangeAuthorizationCode($code, $redirectUri);

        return GoogleCalendarCredential::updateOrCreate(
            ['user_id' => $coach->id],
            [
                'calendar_id' => self::PRIMARY_CALENDAR_ID,
                'access_token' => $token->accessToken,
                'refresh_token' => $token->refreshToken ?? $coach->googleCredential?->refresh_token,
                'token_expires_at' => $token->expiresAt,
                'connected_at' => now(),
            ],
        );
    }

    /**
     * コールバック成功後にリダイレクトすべきパスをセッションから取り出す(無ければ null)。
     */
    public function consumeRedirectPath(): ?string
    {
        $path = Session::pull(self::SESSION_REDIRECT_KEY);

        return is_string($path) ? $path : null;
    }

    public function disconnect(User $coach): void
    {
        $coach->googleCredential?->delete();
    }

    public function isConnected(User $coach): bool
    {
        return $coach->googleCredential !== null;
    }

    /**
     * 指定コーチの指定期間内の Google 側予定(busy interval)一覧を返す。
     *
     * 未連携 / 通信失敗はすべて空 Collection にフォールドし、例外を外へ漏らさない。
     *
     * @return Collection<int, array{start: CarbonInterface, end: CarbonInterface}>
     */
    public function busyIntervals(User $coach, CarbonInterface $from, CarbonInterface $to): Collection
    {
        $credential = $coach->googleCredential;
        if ($credential === null) {
            return collect();
        }

        try {
            $accessToken = $this->freshAccessToken($credential);

            return collect($this->client->listBusyIntervals($accessToken, $credential->calendar_id, $from, $to));
        } catch (Throwable $e) {
            Log::warning('google_calendar.busy_intervals_failed', [
                'coach_id' => $coach->id,
                'exception' => $e->getMessage(),
            ]);

            return collect();
        }
    }

    /**
     * 指定区間が Google 側予定と重なるかどうか。未連携 / 通信失敗は false(空きとして扱う)。
     */
    public function hasConflict(User $coach, CarbonInterface $start, CarbonInterface $end): bool
    {
        return $this->busyIntervals($coach, $start, $end)
            ->contains(fn (array $interval): bool => $start->lt($interval['end']) && $interval['start']->lt($end));
    }

    /**
     * 面談成立時、担当コーチが連携済みなら Google カレンダーへ予定を自動登録する。
     * 未連携 / 通信失敗は静かに no-op(面談予約自体は成立済みのため失敗させない)。
     */
    public function registerMeeting(Meeting $meeting): void
    {
        $coach = $meeting->coach ?? $meeting->loadMissing('coach')->coach;
        $credential = $coach?->googleCredential;
        if ($coach === null || $credential === null) {
            return;
        }

        try {
            $accessToken = $this->freshAccessToken($credential);

            $eventId = $this->client->createEvent($accessToken, $credential->calendar_id, new GoogleCalendarEvent(
                summary: '面談: '.($meeting->topic ?? '(トピック未設定)'),
                // コーチの現在のプロフィール値ではなく、予約時点で固定した値を使う(既存設計: meeting_url_snapshot)
                description: $meeting->meeting_url_snapshot,
                start: $meeting->scheduled_at,
                end: $meeting->scheduled_at->copy()->addHour(),
            ));

            $meeting->update(['google_calendar_event_id' => $eventId]);
        } catch (Throwable $e) {
            Log::warning('google_calendar.register_meeting_failed', [
                'meeting_id' => $meeting->id,
                'coach_id' => $coach->id,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 面談キャンセル時、Google 側に登録済みの予定があれば削除する。
     * 未連携 / 未登録 / 通信失敗は静かに no-op(面談キャンセル自体は成立済みのため失敗させない)。
     */
    public function cancelMeeting(Meeting $meeting): void
    {
        $coach = $meeting->coach ?? $meeting->loadMissing('coach')->coach;
        $credential = $coach?->googleCredential;
        $eventId = $meeting->google_calendar_event_id;
        if ($coach === null || $credential === null || $eventId === null) {
            return;
        }

        try {
            $accessToken = $this->freshAccessToken($credential);
            $this->client->deleteEvent($accessToken, $credential->calendar_id, $eventId);
            $meeting->update(['google_calendar_event_id' => null]);
        } catch (Throwable $e) {
            Log::warning('google_calendar.cancel_meeting_failed', [
                'meeting_id' => $meeting->id,
                'coach_id' => $coach->id,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 有効なアクセストークンを返す。期限切れならリフレッシュトークンで更新し、永続化する。
     *
     * @throws Throwable リフレッシュ自体が失敗した場合(呼び出し元が catch してフォールバックする前提)
     */
    private function freshAccessToken(GoogleCalendarCredential $credential): string
    {
        if ($credential->token_expires_at !== null && $credential->token_expires_at->isFuture()) {
            return $credential->access_token;
        }

        if ($credential->refresh_token === null) {
            throw new \RuntimeException('refresh_token が保存されていないため、アクセストークンを更新できません。');
        }

        $token = $this->client->refreshAccessToken($credential->refresh_token);

        $credential->update([
            'access_token' => $token->accessToken,
            'refresh_token' => $token->refreshToken ?? $credential->refresh_token,
            'token_expires_at' => $token->expiresAt,
        ]);

        return $token->accessToken;
    }
}
