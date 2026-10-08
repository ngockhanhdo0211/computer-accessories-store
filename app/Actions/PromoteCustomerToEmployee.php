<?php

namespace App\Actions;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PromoteCustomerToEmployee
{
    public const AUDIT_ACTION = 'user.employee_bootstrapped';

    /** @var array{role: string, status: string} */
    private const BEFORE = [
        'role' => 'customer',
        'status' => 'active',
    ];

    /** @var array{role: string, status: string} */
    private const AFTER = [
        'role' => 'employee',
        'status' => 'active',
    ];

    public function validateAndNormalizeEmail(string $email): string
    {
        $normalizedEmail = mb_strtolower(trim($email));

        Validator::make(
            ['email' => $normalizedEmail],
            ['email' => ['required', 'string', 'email:rfc', 'max:255']],
        )->validate();

        return $normalizedEmail;
    }

    public function handle(string $email): User
    {
        $normalizedEmail = $this->validateAndNormalizeEmail($email);

        return DB::transaction(function () use ($normalizedEmail): User {
            $user = User::query()
                ->where('email', $normalizedEmail)
                ->lockForUpdate()
                ->first();

            if (! $user instanceof User) {
                $this->fail('Không tìm thấy tài khoản Customer phù hợp.');
            }

            $role = $user->getRawOriginal('role');
            $status = $user->getRawOriginal('status');

            if ($status !== UserStatus::Active->value) {
                $this->fail('Tài khoản phải đang ở trạng thái active.');
            }

            $evidence = AuditLog::query()
                ->where('action', self::AUDIT_ACTION)
                ->where('subject_type', User::class)
                ->where('subject_id', $user->getKey())
                ->lockForUpdate()
                ->get();

            if ($role === UserRole::Employee->value) {
                if (! $this->hasValidEvidence($evidence->all())) {
                    $this->fail('Tài khoản Employee không có audit evidence hợp lệ.');
                }

                return $user;
            }

            if ($role !== UserRole::Customer->value) {
                $this->fail('Chỉ tài khoản Customer mới có thể được nâng thành Employee.');
            }

            if ($evidence->isNotEmpty()) {
                $this->fail('Phát hiện audit evidence không nhất quán cho tài khoản Customer.');
            }

            $promotedAt = now();
            if ($user->updated_at !== null && $promotedAt->lessThanOrEqualTo($user->updated_at)) {
                $promotedAt = $user->updated_at->copy()->addSecond();
            }

            $user->role = UserRole::Employee;
            $user->updated_at = $promotedAt;
            $user->save();

            (new AuditLog)->forceFill([
                'actor_id' => null,
                'action' => self::AUDIT_ACTION,
                'subject_type' => User::class,
                'subject_id' => $user->getKey(),
                'before_json' => self::BEFORE,
                'after_json' => self::AFTER,
                'request_id' => (string) Str::uuid(),
                'created_at' => $promotedAt,
            ])->save();

            return $user->refresh();
        }, 3);
    }

    /** @param array<int, AuditLog> $evidence */
    private function hasValidEvidence(array $evidence): bool
    {
        if (count($evidence) !== 1) {
            return false;
        }

        $audit = $evidence[0];

        return $audit->actor_id === null
            && $audit->before_json === self::BEFORE
            && $audit->after_json === self::AFTER
            && is_string($audit->request_id)
            && Str::isUuid($audit->request_id)
            && $audit->created_at !== null;
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['email' => $message]);
    }
}
