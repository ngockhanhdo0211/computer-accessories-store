@csrf
@if (isset($category)) @method('PUT') @endif
<div class="form-grid">
    <div class="field field--full">
        <label for="name">Tên danh mục <span class="required-hint">(bắt buộc)</span></label>
        <input id="name" name="name" type="text" maxlength="255" autocomplete="off" value="{{ old('name', $category->name ?? '') }}" @error('name') aria-invalid="true" aria-describedby="name-error" @enderror required>
        <p class="field-message @error('name') field-error @enderror" id="name-error">@error('name'){{ $message }}@else &nbsp; @enderror</p>
    </div>
    <div class="field field--full">
        <label for="slug">Slug <span class="required-hint">{{ isset($category) ? '(để trống để giữ slug hiện tại)' : '(để trống để sinh từ tên)' }}</span></label>
        <input id="slug" name="slug" type="text" maxlength="255" autocomplete="off" value="{{ old('slug', $category->slug ?? '') }}" @error('slug') aria-invalid="true" aria-describedby="slug-error" @enderror>
        <p class="field-message @error('slug') field-error @enderror" id="slug-error">@error('slug'){{ $message }}@else Dùng chữ thường, số và dấu gạch ngang. Slug không tự đổi khi sửa tên. @enderror</p>
    </div>
    <div class="field">
        <label for="parent_id">Danh mục cha</label>
        <select id="parent_id" name="parent_id" @error('parent_id') aria-invalid="true" aria-describedby="parent-error" @enderror>
            <option value="">Danh mục gốc</option>
            @foreach ($parents as $parent)
                <option value="{{ $parent->id }}" @selected((string) old('parent_id', $category->parent_id ?? '') === (string) $parent->id)>{{ $parent->name }}{{ $parent->is_visible ? '' : ' (đang ẩn)' }}</option>
            @endforeach
        </select>
        <p class="field-message @error('parent_id') field-error @enderror" id="parent-error">@error('parent_id'){{ $message }}@else &nbsp; @enderror</p>
    </div>
    <div class="field">
        <label for="is_visible">Trạng thái</label>
        <select id="is_visible" name="is_visible" @error('is_visible') aria-invalid="true" aria-describedby="visible-error" @enderror required>
            <option value="1" @selected((string) old('is_visible', isset($category) ? (int) $category->is_visible : 1) === '1')>Đang hiển thị</option>
            <option value="0" @selected((string) old('is_visible', isset($category) ? (int) $category->is_visible : 1) === '0')>Đang ẩn</option>
        </select>
        <p class="field-message @error('is_visible') field-error @enderror" id="visible-error">@error('is_visible'){{ $message }}@else Category ẩn sẽ không được bán công khai khi Product được triển khai. @enderror</p>
    </div>
</div>
<div class="form-actions">
    <button class="button" type="submit">{{ isset($category) ? 'Lưu thay đổi' : 'Tạo danh mục' }}</button>
    <a class="text-link" href="{{ route('admin.categories.index') }}">Quay lại danh sách</a>
</div>