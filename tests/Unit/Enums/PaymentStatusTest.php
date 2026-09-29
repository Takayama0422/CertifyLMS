<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\PaymentStatus;
use Tests\TestCase;

class PaymentStatusTest extends TestCase
{
    public function test_enum_lists_four_values(): void
    {
        $values = array_map(fn (PaymentStatus $s) => $s->value, PaymentStatus::cases());

        $this->assertEqualsCanonicalizing(
            ['pending', 'succeeded', 'failed', 'refunded'],
            $values,
        );
    }

    public function test_japanese_labels(): void
    {
        $this->assertSame('保留', PaymentStatus::Pending->label());
        $this->assertSame('完了', PaymentStatus::Succeeded->label());
        $this->assertSame('失敗', PaymentStatus::Failed->label());
        $this->assertSame('返金済', PaymentStatus::Refunded->label());
    }
}
