@csrf
@if (isset($product)) @method('PUT') @endif

<div class="product-form-grid">
    <section class="form-panel product-form-section" aria-labelledby="product-basic-title">
        <h2 id="product-basic-title">Thông tin sản phẩm</h2>
        <div class="form-grid">
            <div class="field field--full">
                <label for="name">Tên sản phẩm <span class="required-hint">(bắt buộc)</span></label>
                <input id="name" name="name" type="text" maxlength="255" value="{{ old('name', $product->name ?? '') }}" autocomplete="off" @error('name') aria-invalid="true" aria-describedby="name-error" @enderror required>
                <p class="field-message @error('name') field-error @enderror" id="name-error">@error('name'){{ $message }}@else &nbsp; @enderror</p>
            </div>
            <div class="field">
                <label for="sku">SKU <span class="required-hint">(bắt buộc)</span></label>
                <input id="sku" name="sku" type="text" maxlength="80" value="{{ old('sku', $product->sku ?? '') }}" autocomplete="off" @error('sku') aria-invalid="true" aria-describedby="sku-error" @enderror required>
                <p class="field-message @error('sku') field-error @enderror" id="sku-error">@error('sku'){{ $message }}@else Viết hoa; dùng chữ, số, dấu chấm, gạch ngang hoặc gạch dưới. @enderror</p>
            </div>
            <div class="field">
                <label for="slug">Slug <span class="required-hint">{{ isset($product) ? '(để trống để giữ hiện tại)' : '(để trống để sinh từ tên)' }}</span></label>
                <input id="slug" name="slug" type="text" maxlength="255" value="{{ old('slug', $product->slug ?? '') }}" autocomplete="off" @error('slug') aria-invalid="true" aria-describedby="slug-error" @enderror>
                <p class="field-message @error('slug') field-error @enderror" id="slug-error">@error('slug'){{ $message }}@else Slug không tự đổi khi chỉ sửa tên. @enderror</p>
            </div>
            <div class="field">
                <label for="category_id">Danh mục</label>
                <select id="category_id" name="category_id" @error('category_id') aria-invalid="true" aria-describedby="category-error" @enderror required>
                    <option value="">Chọn danh mục</option>
                    @foreach ($categories as $category)
                        <option value="{{ $category->id }}" @selected((string) old('category_id', $product->category_id ?? '') === (string) $category->id)>
                            {{ $category->name }}{{ $category->is_visible ? '' : ' — đang ẩn' }}
                        </option>
                    @endforeach
                </select>
                <p class="field-message @error('category_id') field-error @enderror" id="category-error">@error('category_id'){{ $message }}@else Danh mục ẩn làm sản phẩm không xuất hiện công khai. @enderror</p>
            </div>
            <div class="field">
                <label for="brand_id">Thương hiệu</label>
                <select id="brand_id" name="brand_id" @error('brand_id') aria-invalid="true" aria-describedby="brand-error" @enderror required>
                    <option value="">Chọn thương hiệu</option>
                    @foreach ($brands as $brand)
                        <option value="{{ $brand->id }}" @selected((string) old('brand_id', $product->brand_id ?? '') === (string) $brand->id)>
                            {{ $brand->name }}{{ $brand->is_visible ? '' : ' — đang ẩn' }}
                        </option>
                    @endforeach
                </select>
                <p class="field-message @error('brand_id') field-error @enderror" id="brand-error">@error('brand_id'){{ $message }}@else Thương hiệu ẩn làm sản phẩm không xuất hiện công khai. @enderror</p>
            </div>
            <div class="field">
                <label for="price_vnd">Giá gốc (VND)</label>
                <input id="price_vnd" name="price_vnd" type="number" min="1" step="1" inputmode="numeric" value="{{ old('price_vnd', $product->price_vnd ?? '') }}" @error('price_vnd') aria-invalid="true" aria-describedby="price-error" @enderror required>
                <p class="field-message @error('price_vnd') field-error @enderror" id="price-error">@error('price_vnd'){{ $message }}@else Nhập số nguyên, không dùng dấu phân cách. @enderror</p>
            </div>
            <div class="field">
                <label for="sale_price_vnd">Giá khuyến mãi (VND)</label>
                <input id="sale_price_vnd" name="sale_price_vnd" type="number" min="1" step="1" inputmode="numeric" value="{{ old('sale_price_vnd', $product->sale_price_vnd ?? '') }}" @error('sale_price_vnd') aria-invalid="true" aria-describedby="sale-price-error" @enderror>
                <p class="field-message @error('sale_price_vnd') field-error @enderror" id="sale-price-error">@error('sale_price_vnd'){{ $message }}@else Để trống nếu không có giá khuyến mãi. @enderror</p>
            </div>
            <div class="field field--full">
                <label for="short_description">Mô tả ngắn</label>
                <textarea id="short_description" name="short_description" rows="3" maxlength="1000" @error('short_description') aria-invalid="true" aria-describedby="short-description-error" @enderror required>{{ old('short_description', $product->short_description ?? '') }}</textarea>
                <p class="field-message @error('short_description') field-error @enderror" id="short-description-error">@error('short_description'){{ $message }}@else Tóm tắt rõ công dụng chính của phụ kiện. @enderror</p>
            </div>
            <div class="field field--full">
                <label for="description">Mô tả chi tiết</label>
                <textarea id="description" name="description" rows="8" maxlength="20000" @error('description') aria-invalid="true" aria-describedby="description-error" @enderror required>{{ old('description', $product->description ?? '') }}</textarea>
                <p class="field-message @error('description') field-error @enderror" id="description-error">@error('description'){{ $message }}@else Lưu dưới dạng văn bản thuần, không chèn HTML. @enderror</p>
            </div>
            <div class="field field--full">
                <label for="visibility">Trạng thái</label>
                <select id="visibility" name="visibility" required>
                    @foreach ($visibilities as $visibility)
                        <option value="{{ $visibility->value }}" @selected(old('visibility', isset($product) ? $product->visibility->value : 'active') === $visibility->value)>{{ $visibility->label() }}</option>
                    @endforeach
                </select>
                <p class="field-message">@error('visibility')<span class="field-error">{{ $message }}</span>@else Sản phẩm chỉ công khai khi chính nó, danh mục và thương hiệu đều đang hiển thị. @enderror</p>
            </div>
        </div>
    </section>

    <aside class="form-panel product-form-section product-form-media" aria-labelledby="product-media-title">
        <h2 id="product-media-title">Ảnh sản phẩm</h2>
        <p>Tối đa 8 ảnh JPG, PNG hoặc WebP; mỗi ảnh không quá 5 MB. Ảnh đầu tiên sẽ làm đại diện nếu sản phẩm chưa có ảnh.</p>
        <div class="field">
            <label for="images">Chọn ảnh</label>
            <input id="images" name="images[]" type="file" accept="image/jpeg,image/png,image/webp" multiple data-product-image-input @error('images') aria-invalid="true" aria-describedby="images-error" @enderror>
            <p class="field-message @error('images') field-error @enderror" id="images-error">@error('images'){{ $message }}@else Tên file lưu trên server được tạo tự động. @enderror</p>
        </div>
        <div class="product-upload-preview" data-product-image-preview aria-live="polite"></div>
        @error('images.*')<p class="field-error">{{ $message }}</p>@enderror
    </aside>
</div>

<div class="form-actions">
    <button class="button" type="submit">{{ isset($product) ? 'Lưu thay đổi' : 'Tạo sản phẩm' }}</button>
    <a class="button button--quiet" href="{{ route('admin.products.index') }}">Hủy</a>
</div>