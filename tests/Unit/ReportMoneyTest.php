<?php

namespace Tests\Unit;

use App\Services\ReportMoney;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ReportMoneyTest extends TestCase
{
    public function test_exact_arithmetic_and_aggregate_precision(): void
    {
        $this->assertSame('0.30', ReportMoney::add('0.10', '0.20'));
        $this->assertSame('19999999999999999.98', ReportMoney::add('9999999999999999.99', '9999999999999999.99'));
        $this->assertSame('-0.01', ReportMoney::subtract('0.00', '0.01'));
        $this->assertSame('0.00', ReportMoney::stored('0'));
        $this->assertNull(ReportMoney::contribution('0.00', 'SDG'));
        $this->assertNull(ReportMoney::contribution('1.00', 'USD'));
        $this->assertNull(ReportMoney::add(null, '1.00'));
        $this->assertNull(ReportMoney::subtract('1.00', null));
    }

    public static function invalid(): array
    {
        return array_map(fn ($value) => [$value], [null, 1, 0.1, [], '', '-1', '+1', '01.00', '1e2', '1.001', ' 1', '10000000000000000.00', 'NaN']);
    }

    #[DataProvider('invalid')]
    public function test_malformed_stored_money_is_unavailable(mixed $value): void
    {
        $this->assertNull(ReportMoney::stored($value));
    }
}
