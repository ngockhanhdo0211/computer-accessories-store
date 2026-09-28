@csrf
@if (isset($brand))
    @method('PUT')
@endif
<div class="form-grid">
    <div class="field field--full">
        <label for="name">Tên thương hiệu <span class="required-hint">(bắt buộc)</span></label>
        <input id="name" name="name" type="text" maxlength="255" autocomplete="organization" value="{{ old('name', $brand->name ?? '') }}" aria-describedby="name-message" @error('name') aria-invalid="true" @enderror required>
        <p class="field-message @error('name') field-error @enderror" id="name-message">@error('name'){{ $message }}@else Tên dùng để nhận biết thương hiệu trong catalog. @enderror</p>
    </div>
    <div class="field field--full">
        <label for="slug">Slug <span class="required-hint">{{ isset($brand) ? '(để trống để giữ slug hiện tại)' : '(để trống để sinh từ tên)' }}</span></label>
        <input id="slug" name="slug" type="text" maxlength="255" autocomplete="off" value="{{ old('slug', $brand->slug ?? '') }}" aria-describedby="slug-message" @error('slug') aria-invalid="true" @enderror>
        <p class="field-message @error('slug') field-error @enderror" id="slug-message">@error('slug'){{ $message }}@else Dùng chữ thường, số và dấu gạch ngang. Slug không tự đổi khi sửa tên. @enderror</p>
    </div>
    <div class="field field--full">
        <label for="is_visible">Trạng thái</label>
        <select id="is_visible" name="is_visible" aria-describedby="visible-message" @error('is_visible') aria-invalid="true" @enderror required>
            <option value="1" @selected((string) old('is_visible', isset($brand) ? (int) $brand->is_visible : 1) === '1')>Đang hiển thị</option>
            <option value="0" @selected((string) old('is_visible', isset($brand) ? (int) $brand->is_visible : 1) === '0')>Đang ẩn</option>
        </select>
        <p class="field-message @error('is_visible') field-error @enderror" id="visible-message">@error('is_visible'){{ $message }}@else Thương hiệu ẩn làm sản phẩm liên quan không hiển thị công khai. @enderror</p>
    </div>
</div>
<div class="action-group resource-form__actions">
    <button class="button" type="submit">{{ isset($brand) ? 'Lưu thay đổi' : 'Tạo thương hiệu' }}</button>
    <a class="button button--quiet" href="{{ route('admin.brands.index') }}">Hủy</a>
</div>
