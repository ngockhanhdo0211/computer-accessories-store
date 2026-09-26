@csrf
@if (isset($brand)) @method('PUT') @endif
<div class="form-grid">
    <div class="field field--full">
        <label for="name">Tên thương hiệu <span class="required-hint">(bắt buộc)</span></label>
        <input id="name" name="name" type="text" maxlength="255" autocomplete="organization" value="{{ old('name', $brand->name ?? '') }}" @error('name') aria-invalid="true" aria-describedby="name-error" @enderror required>
        <p class="field-message @error('name') field-error @enderror" id="name-error">@error('name'){{ $message }}@else &nbsp; @enderror</p>
    </div>
    <div class="field field--full">
        <label for="slug">Slug <span class="required-hint">{{ isset($brand) ? '(để trống để giữ slug hiện tại)' : '(để trống để sinh từ tên)' }}</span></label>
        <input id="slug" name="slug" type="text" maxlength="255" autocomplete="off" value="{{ old('slug', $brand->slug ?? '') }}" @error('slug') aria-invalid="true" aria-describedby="slug-error" @enderror>
        <p class="field-message @error('slug') field-error @enderror" id="slug-error">@error('slug'){{ $message }}@else Dùng chữ thường, số và dấu gạch ngang. Slug không tự đổi khi sửa tên. @enderror</p>
    </div>
    <div class="field field--full">
        <label for="is_visible">Trạng thái</label>
        <select id="is_visible" name="is_visible" @error('is_visible') aria-invalid="true" aria-describedby="visible-error" @enderror required>
            <option value="1" @selected((string) old('is_visible', isset($brand) ? (int) $brand->is_visible : 1) === '1')>Đang hiển thị</option>
            <option value="0" @selected((string) old('is_visible', isset($brand) ? (int) $brand->is_visible : 1) === '0')>Đang ẩn</option>
        </select>
        <p class="field-message @error('is_visible') field-error @enderror" id="visible-error">@error('is_visible'){{ $message }}@else Thương hiệu ẩn sẽ không cho phép sản phẩm liên quan hiển thị công khai khi Product được triển khai. @enderror</p>
    </div>
</div>
<div class="form-actions">
    <button class="button" type="submit">{{ isset($brand) ? 'Lưu thay đổi' : 'Tạo thương hiệu' }}</button>
    <a class="button button--quiet" href="{{ route('admin.brands.index') }}">Hủy</a>
</div>
