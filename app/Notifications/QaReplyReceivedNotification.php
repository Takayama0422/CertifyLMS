<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\NotificationType;
use App\Models\QaReply;
use Illuminate\Support\Str;

/**
 * 質問掲示板の回答投稿通知。スレッド投稿者へ配信する(回答者本人がスレッド投稿者の場合は呼び出し側で除外)。
 */
final class QaReplyReceivedNotification extends BusinessEventNotification
{
    public function __construct(private readonly QaReply $reply) {}

    public function type(): NotificationType
    {
        return NotificationType::QaReplyReceived;
    }

    public function title(): string
    {
        return '質問「'.$this->reply->thread->title.'」に回答が届きました';
    }

    public function message(): string
    {
        return $this->reply->user->name.'さんが回答しました：'.Str::limit($this->reply->body, 60);
    }

    public function body(): string
    {
        return $this->reply->user->name."さんが以下の回答を投稿しました。\n\n".$this->reply->body;
    }

    public function url(): string
    {
        return route('qa-board.show', $this->reply->qa_thread_id);
    }
}
