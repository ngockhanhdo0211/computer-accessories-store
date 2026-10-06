<?php

namespace App\Console\Commands;

use App\Actions\PromoteCustomerToAdmin;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use Throwable;

class PromoteCustomerToAdminCommand extends Command
{
    protected $signature = 'app:promote-customer-to-admin {email : Email của Customer hiện hữu}';

    protected $description = 'Promote one existing active Customer account to Admin';

    public function handle(PromoteCustomerToAdmin $promoteCustomerToAdmin): int
    {
        if (config('app.env') === 'production') {
            if (! $this->input->isInteractive()) {
                $this->error('Production yêu cầu xác nhận tương tác; không có tài khoản nào được thay đổi.');

                return self::FAILURE;
            }

            if (! $this->confirm('APP_ENV=production. Bạn có chắc muốn nâng Customer đã chỉ định thành Admin?')) {
                $this->warn('Đã hủy; không có tài khoản nào được thay đổi.');

                return self::FAILURE;
            }
        }

        try {
            $promoteCustomerToAdmin->handle((string) $this->argument('email'));
        } catch (ValidationException $exception) {
            $this->error($exception->validator->errors()->first('email'));

            return self::FAILURE;
        } catch (Throwable) {
            $this->error('Không thể nâng tài khoản thành Admin; không có thay đổi nào được xác nhận.');

            return self::FAILURE;
        }

        $this->info('Tài khoản Admin đã sẵn sàng.');

        return self::SUCCESS;
    }
}
