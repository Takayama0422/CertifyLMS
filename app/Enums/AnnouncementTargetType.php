<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * お知らせ配信の対象タイプ(要件シート S3: 3 択、支給画面
 * `announcement/management/_partials/target-fields.blade.php` が値・ラベルを前提にしている)。
 */
enum AnnouncementTargetType: string
{
    case AllStudents = 'all_students';
    case Certification = 'certification';
    case User = 'user';

    public function label(): string
    {
        return match ($this) {
            self::AllStudents => '全受講生',
            self::Certification => '資格指定',
            self::User => 'ユーザー指定',
        };
    }
}
