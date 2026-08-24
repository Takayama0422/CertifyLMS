<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Invitation;
use App\Services\InvitationTokenService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * 招待メール(T-A-05: 非同期化)。
 *
 * `ShouldQueueAfterCommit` を実装し、送信をバックグラウンドのキューへ逃がす。
 * `ShouldQueue` を継承しつつ、発火元(`IssueInvitationAction`)が `DB::transaction()` 内から
 * `Mail::send()` を呼んでいても、実際にキューへ積まれるのはトランザクション commit 後になる
 * (ロールバック時に送信が漏れない)。トランザクションの外から呼ばれた場合は即座にキューへ積まれる。
 *
 * 一時的な送信失敗(SMTP 接続エラー等)は `$tries` / `backoff()` の段階的リトライで吸収し、
 * 上限を超えたものは `failed_jobs` に記録される(`php artisan queue:retry` で再投入可能)。
 */
class InvitationMail extends Mailable implements ShouldQueueAfterCommit
{
    use Queueable, SerializesModels;

    /** 最大試行回数(初回 + 4 回のリトライ)。超過分は failed_jobs へ記録される。 */
    public int $tries = 5;

    public function __construct(public Invitation $invitation) {}

    /**
     * リトライ間隔(秒)。1 回ごとに段階的に待機時間を伸ばす。
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 30, 60, 300, 900];
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            to: [$this->invitation->email],
            subject: 'Certify LMS への招待',
        );
    }

    public function content(): Content
    {
        $url = app(InvitationTokenService::class)->generateUrl($this->invitation);

        return new Content(
            markdown: 'emails.invitation',
            with: [
                'invitation' => $this->invitation,
                'invitedBy' => $this->invitation->invitedBy,
                'roleLabel' => $this->invitation->role->label(),
                'expiresAt' => $this->invitation->expires_at,
                'url' => $url,
            ],
        );
    }
}
