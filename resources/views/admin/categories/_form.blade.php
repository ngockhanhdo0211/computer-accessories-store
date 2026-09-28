@csrf
@if (isset($category))
    @method('PUT')
@endif

<section class="category-editor__section" aria-labelledby="category-identity-title">
    <header>
        <div>
            <h2 id="category-identity-title">Định danh</h2>
            <p>Tên dùng để nhận biết; slug dùng trong đường dẫn.</p>
        </div>
    </header>
    <div class="form-grid">
        <div class="field field--full">
            <label for="name">Tên danh mục <span class="required-hint">(bắt buộc)</span></label>
            <input id="name" name="name" type="text" maxlength="255" autocomplete="off" value="{{ old('name', $category->name ?? '') }}" aria-describedby="name-message" @error('name') aria-invalid="true" @enderror required>
            <p class="field-message @error('name') field-error @enderror" id="name-message">@error('name'){{ $message }}@else Tên hiển thị trong cấu trúc catalog và khu vực quản trị. @enderror</p>
        </div>
        <div class="field field--full">
            <label for="slug">Slug <span class="required-hint">{{ isset($category) ? '(để trống để giữ slug hiện tại)' : '(để trống để sinh từ tên)' }}</span></label>
            <input id="slug" name="slug" type="text" maxlength="255" autocomplete="off" value="{{ old('slug', $category->slug ?? '') }}" aria-describedby="slug-message" @error('slug') aria-invalid="true" @enderror>
            <p class="field-message @error('slug') field-error @enderror" id="slug-message">@error('slug'){{ $message }}@else Chỉ dùng chữ thường, số và dấu gạch ngang. @enderror</p>
        </div>
    </div>
</section>

<section class="category-editor__section" aria-labelledby="category-structure-title">
    <header>
        <div>
            <h2 id="category-structure-title">Cấu trúc và hiển thị</h2>
            <p>Xác định vị trí của danh mục và khả năng xuất hiện công khai.</p>
        </div>
    </header>
    <div class="form-grid">
        <div class="field">
            <label for="parent_id">Danh mục cha</label>
            <select id="parent_id" name="parent_id" aria-describedby="parent-message" @error('parent_id') aria-invalid="true" @enderror>
                <option value="">Danh mục gốc</option>
                @foreach ($parents as $parent)
                    <option value="{{ $parent->id }}" @selected((string) old('parent_id', $category->parent_id ?? '') === (string) $parent->id)>{{ $parent->name }}{{ $parent->is_visible ? '' : ' (đang ẩn)' }}</option>
                @endforeach
            </select>
            <p class="field-message @error('parent_id') field-error @enderror" id="parent-message">@error('parent_id'){{ $message }}@else Để trống nếu đây là nhóm phụ kiện cấp cao nhất. @enderror</p>
        </div>
        <div class="field">
            <label for="is_visible">Trạng thái</label>
            <select id="is_visible" name="is_visible" aria-describedby="visible-message" @error('is_visible') aria-invalid="true" @enderror required>
                <option value="1" @selected((string) old('is_visible', isset($category) ? (int) $category->is_visible : 1) === '1')>Đang hiển thị</option>
                <option value="0" @selected((string) old('is_visible', isset($category) ? (int) $category->is_visible : 1) === '0')>Đang ẩn</option>
            </select>
            <p class="field-message @error('is_visible') field-error @enderror" id="visible-message">@error('is_visible'){{ $message }}@else Danh mục ẩn làm sản phẩm liên quan không hiển thị công khai. @enderror</p>
        </div>
    </div>
</section>

<footer class="category-editor__actions">
    <p>Kiểm tra lại cấu trúc trước khi lưu.</p>
    <div class="action-group">
        <button class="button" type="submit">{{ isset($category) ? 'Lưu thay đổi' : 'Tạo danh mục' }}</button>
        <a class="button button--quiet" href="{{ route('admin.categories.index') }}">Hủy</a>
    </div>
</footer>
