<?php

namespace App\Console\Commands;

use App\Actions\PromoteCustomerToEmployee;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use Throwable;

class PromoteCustomerToEmployeeCommand extends Command
{
    protected $signature = 'app:promote-customer-to-employee {email : Email của Customer hiện hữu}';

    protected $description = 'Promote one existing active Customer account to Employee';

    public function handle(PromoteCustomerToEmployee $promoteCustomerToEmployee): int
    {
        try {
            $email = $promoteCustomerToEmployee->validateAndNormalizeEmail((string) $this->argument('email'));
        } catch (ValidationException $exception) {
            $this->error($exception->validator->errors()->first('email'));

            return self::FAILURE;
        }

        if (config('app.env') === 'production') {
            if (! $this->input->isInteractive()) {
                $this->error('Production yêu cầu xác nhận tương tác; không có tài khoản nào được thay đổi.');

                return self::FAILURE;
            }

            $this->warn('Employee có quyền xử lý đơn hàng, vận chuyển, tồn kho và Support Chat theo ma trận quyền hiện hành.');
            $this->line('Email mục tiêu: '.$email);

            if (! $this->confirm('APP_ENV=production. Bạn có chắc muốn nâng Customer này thành Employee?')) {
                $this->warn('Đã hủy; không có tài khoản nào được thay đổi.');

                return self::FAILURE;
            }
        }

        try {
            $promoteCustomerToEmployee->handle($email);
        } catch (ValidationException $exception) {
            $this->error($exception->validator->errors()->first('email'));

            return self::FAILURE;
        } catch (Throwable) {
            $this->error('Không thể nâng tài khoản thành Employee; không có thay đổi nào được xác nhận.');

            return self::FAILURE;
        }

        $this->info('Tài khoản Employee đã sẵn sàng.');

        return self::SUCCESS;
    }
}
