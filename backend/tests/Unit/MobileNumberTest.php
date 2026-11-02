<?php

namespace Tests\Unit;

use App\Support\MobileNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MobileNumberTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function numbers(): array
    {
        return [
            'plain' => ['9876543210', '9876543210'],
            'with spaces' => ['98765 43210', '9876543210'],
            'with +91 and a dash' => ['+91 98765-43210', '9876543210'],
            'with 91 and no plus' => ['919876543210', '9876543210'],
            'with a leading 0' => ['09876543210', '9876543210'],
            'too short, kept for validation to reject' => ['12345', '12345'],
        ];
    }

    #[DataProvider('numbers')]
    public function test_numbers_typed_any_common_way_become_ten_digits(string $typed, string $saved): void
    {
        $this->assertSame($saved, MobileNumber::normalize($typed));
    }

    public function test_the_pattern_accepts_only_indian_mobile_numbers(): void
    {
        $this->assertMatchesRegularExpression(MobileNumber::PATTERN, '9876543210');
        $this->assertMatchesRegularExpression(MobileNumber::PATTERN, '6000000000');
        $this->assertDoesNotMatchRegularExpression(MobileNumber::PATTERN, '5876543210');
        $this->assertDoesNotMatchRegularExpression(MobileNumber::PATTERN, '987654321');
    }
}
