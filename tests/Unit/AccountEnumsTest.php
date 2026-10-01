<?php

namespace Tests\Unit;

use App\Enums\MembershipLevel;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class AccountEnumsTest extends TestCase
{
    public function test_account_enums_have_only_the_agreed_string_values(): void
    {
        $this->assertSame(['customer', 'employee', 'admin'], array_column(UserRole::cases(), 'value'));
        $this->assertSame(['active', 'locked', 'inactive'], array_column(UserStatus::cases(), 'value'));
        $this->assertSame(['dong', 'bac', 'vang', 'kim_cuong'], array_column(MembershipLevel::cases(), 'value'));
    }

    public function test_enum_labels_are_available_for_display(): void
    {
        $this->assertSame('Khách hàng', UserRole::Customer->label());
        $this->assertSame('Tạm khóa', UserStatus::Locked->label());
        $this->assertSame('Kim cương', MembershipLevel::KimCuong->label());
    }

    public function test_membership_level_uses_the_documented_vnd_thresholds(): void
    {
        $this->assertSame(MembershipLevel::Dong, MembershipLevel::fromSpendingVnd(0));
        $this->assertSame(MembershipLevel::Dong, MembershipLevel::fromSpendingVnd(4_999_999));
        $this->assertSame(MembershipLevel::Bac, MembershipLevel::fromSpendingVnd(5_000_000));
        $this->assertSame(MembershipLevel::Bac, MembershipLevel::fromSpendingVnd(14_999_999));
        $this->assertSame(MembershipLevel::Vang, MembershipLevel::fromSpendingVnd(15_000_000));
        $this->assertSame(MembershipLevel::Vang, MembershipLevel::fromSpendingVnd(29_999_999));
        $this->assertSame(MembershipLevel::KimCuong, MembershipLevel::fromSpendingVnd(30_000_000));
        $this->assertSame(MembershipLevel::KimCuong, MembershipLevel::fromSpendingVnd(PHP_INT_MAX));

        $this->expectException(InvalidArgumentException::class);
        MembershipLevel::fromSpendingVnd(-1);
    }
}
