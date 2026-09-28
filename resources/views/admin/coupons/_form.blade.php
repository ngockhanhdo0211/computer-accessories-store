@csrf
@php
    $editing = isset($coupon);
    $scopeValue = old('scope', $editing ? $coupon->scope->value : 'cart');
    $typeValue = old('type', $editing ? $coupon->type->value : 'percent');
    $selected = collect(old('target_ids', $selectedTargets ?? []))->map(fn ($id) => (int) $id)->all();
@endphp

<div class="form-grid coupon-form-grid" data-coupon-form>
    <div class="field">
        <label for="code">Mã giảm giá <span class="required-hint">(bắt buộc)</span></label>
        <input id="code" name="code" type="text" maxlength="80" autocomplete="off" value="{{ old('code', $coupon->code ?? '') }}" @error('code') aria-invalid="true" aria-describedby="code-error" @enderror required>
        <p class="field-message @error('code') field-error @enderror" id="code-error">@error('code'){{ $message }}@else Chữ in hoa, số, dấu gạch ngang hoặc gạch dưới. @enderror</p>
    </div>
    <div class="field">
        <label for="type">Loại ưu đãi</label>
        <select id="type" name="type" data-coupon-type required @error('type') aria-invalid="true" aria-describedby="type-error" @enderror>
            @foreach(\App\Enums\CouponType::cases() as $type)
                <option value="{{ $type->value }}" @selected($typeValue === $type->value)>{{ $type->label() }}</option>
            @endforeach
        </select>
        @error('type')<p class="field-message field-error" id="type-error">{{ $message }}</p>@enderror
    </div>
    <div class="field">
        <label for="value">Giá trị ưu đãi</label>
        <div class="coupon-value-control">
            <input id="value" name="value" type="text" inputmode="numeric" pattern="[0-9]+" value="{{ old('value', $coupon->value ?? 0) }}" data-coupon-value aria-describedby="value-help @error('value') value-error @enderror" @error('value') aria-invalid="true" @enderror required>
            <span class="coupon-value-suffix" data-coupon-value-suffix aria-hidden="true">%</span>
        </div>
        <p class="field-message" id="value-help" data-coupon-value-help>Chỉ nhập số nguyên từ 1 đến 100.</p>
        @error('value')<p class="field-message field-error" id="value-error">{{ $message }}</p>@enderror
    </div>
    <div class="field">
        <label for="scope">Phạm vi</label>
        <select id="scope" name="scope" data-coupon-scope required @error('scope') aria-invalid="true" aria-describedby="scope-error" @enderror>
            @foreach(\App\Enums\CouponScope::cases() as $scope)
                <option value="{{ $scope->value }}" @selected($scopeValue === $scope->value)>{{ $scope->label() }}</option>
            @endforeach
        </select>
        @error('scope')<p class="field-message field-error" id="scope-error">{{ $message }}</p>@enderror
    </div>
    <div class="field">
        <label for="min_subtotal_vnd">Tổng tối thiểu đủ điều kiện (VND)</label>
        <input id="min_subtotal_vnd" name="min_subtotal_vnd" type="text" inputmode="numeric" pattern="[0-9]+" value="{{ old('min_subtotal_vnd', $coupon->min_subtotal_vnd ?? 0) }}" @error('min_subtotal_vnd') aria-invalid="true" aria-describedby="min-subtotal-error" @enderror required>
        @error('min_subtotal_vnd')<p class="field-message field-error" id="min-subtotal-error">{{ $message }}</p>@enderror
    </div>
    <div class="field">
        <label for="required_tier">Hạng tối thiểu</label>
        <select id="required_tier" name="required_tier" @error('required_tier') aria-invalid="true" aria-describedby="required-tier-error" @enderror>
            <option value="">Mọi hạng</option>
            @foreach(\App\Enums\MembershipLevel::cases() as $tier)
                <option value="{{ $tier->value }}" @selected(old('required_tier', $coupon->required_tier?->value ?? '') === $tier->value)>{{ $tier->label() }}</option>
            @endforeach
        </select>
        @error('required_tier')<p class="field-message field-error" id="required-tier-error">{{ $message }}</p>@enderror
    </div>
    <div class="field">
        <label for="max_uses">Tổng lượt tối đa</label>
        <input id="max_uses" name="max_uses" type="text" inputmode="numeric" value="{{ old('max_uses', $coupon->max_uses ?? '') }}" placeholder="Không giới hạn" @error('max_uses') aria-invalid="true" aria-describedby="max-uses-error" @enderror>
        @error('max_uses')<p class="field-message field-error" id="max-uses-error">{{ $message }}</p>@enderror
    </div>
    <div class="field">
        <label for="max_uses_per_user">Lượt tối đa mỗi khách</label>
        <input id="max_uses_per_user" name="max_uses_per_user" type="text" inputmode="numeric" value="{{ old('max_uses_per_user', $coupon->max_uses_per_user ?? '') }}" placeholder="Không giới hạn" @error('max_uses_per_user') aria-invalid="true" aria-describedby="max-uses-user-error" @enderror>
        @error('max_uses_per_user')<p class="field-message field-error" id="max-uses-user-error">{{ $message }}</p>@enderror
    </div>
    <div class="field">
        <label for="starts_at">Bắt đầu (UTC)</label>
        <input id="starts_at" name="starts_at" type="datetime-local" value="{{ old('starts_at', isset($coupon) ? $coupon->starts_at->format('Y-m-d\TH:i') : now()->format('Y-m-d\TH:i')) }}" @error('starts_at') aria-invalid="true" aria-describedby="starts-at-error" @enderror required>
        @error('starts_at')<p class="field-message field-error" id="starts-at-error">{{ $message }}</p>@enderror
    </div>
    <div class="field">
        <label for="ends_at">Kết thúc (UTC)</label>
        <input id="ends_at" name="ends_at" type="datetime-local" value="{{ old('ends_at', isset($coupon) ? $coupon->ends_at->format('Y-m-d\TH:i') : now()->addMonth()->format('Y-m-d\TH:i')) }}" @error('ends_at') aria-invalid="true" aria-describedby="ends-at-error" @enderror required>
        @error('ends_at')<p class="field-message field-error" id="ends-at-error">{{ $message }}</p>@enderror
    </div>
</div>

<label class="check-row">
    <input type="hidden" name="is_active" value="0">
    <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $coupon->is_active ?? true))>
    Đang kích hoạt
</label>

<section class="coupon-targets" aria-labelledby="coupon-target-title" aria-describedby="target-help">
    <div>
        <h2 id="coupon-target-title">Mục tiêu theo phạm vi</h2>
        <p id="target-help">Toàn giỏ hàng không cần mục tiêu. Các phạm vi khác cần ít nhất một lựa chọn trong đúng nhóm.</p>
    </div>
    @error('target_ids')<p class="field-message field-error" role="alert">{{ $message }}</p>@enderror

    <fieldset data-coupon-target-group="product">
        <legend>Sản phẩm</legend>
        <div class="coupon-target-list">
            @forelse($products as $product)
                <label><input type="checkbox" name="product_ids[]" value="{{ $product->id }}" @checked($scopeValue === 'product' && in_array($product->id, $selected, true))> <span>{{ $product->name }} <small>{{ $product->sku }}</small></span></label>
            @empty
                <p>Chưa có sản phẩm.</p>
            @endforelse
        </div>
    </fieldset>
    <fieldset data-coupon-target-group="category">
        <legend>Danh mục</legend>
        <div class="coupon-target-list">
            @forelse($categories as $category)
                <label><input type="checkbox" name="category_ids[]" value="{{ $category->id }}" @checked($scopeValue === 'category' && in_array($category->id, $selected, true))> {{ $category->name }}</label>
            @empty
                <p>Chưa có danh mục.</p>
            @endforelse
        </div>
    </fieldset>
    <fieldset data-coupon-target-group="brand">
        <legend>Thương hiệu</legend>
        <div class="coupon-target-list">
            @forelse($brands as $brand)
                <label><input type="checkbox" name="brand_ids[]" value="{{ $brand->id }}" @checked($scopeValue === 'brand' && in_array($brand->id, $selected, true))> {{ $brand->name }}</label>
            @empty
                <p>Chưa có thương hiệu.</p>
            @endforelse
        </div>
    </fieldset>
</section>
