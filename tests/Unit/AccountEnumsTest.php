<?php

namespace Tests\Unit;

use App\Enums\MembershipLevel;
use App\Enums\UserRole;
use App\Enums\UserStatus;
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
}
