# Thiết kế giao diện — Trạm Phụ Kiện

## Định hướng

Website bán phụ kiện laptop với cảm giác cửa hàng công nghệ gọn, rõ và đáng tin. Giao diện chỉ mô tả những chức năng đã có; khu vực chưa có catalog sản phẩm không hiển thị giá, nút mua hoặc đánh giá giả.

## Hệ thống chung

- **Thể loại:** modern minimal cho retail công nghệ.
- **Màu:** giấy sáng hơi ấm, mực graphite và ultramarine hiện có làm điểm nhấn. Dùng token trong `tokens.css` cho mọi màu.
- **Chữ:** Bahnschrift cho tiêu đề và số; Segoe UI cho nội dung. Không dùng chữ nghiêng ở tiêu đề.
- **Nhịp:** lưới đều, đường kẻ mảnh, khoảng trắng có chủ đích. Khu vực quản trị ưu tiên mật độ dữ liệu; trang chủ ưu tiên hình ảnh sản phẩm và CTA thực.
- **Chuyển động:** chỉ phản hồi hover/active ngắn; hỗ trợ reduced motion.
- **Điều hướng:** giữ các route và quyền hiện tại, nút dẫn chỉ tới chức năng đã tồn tại.

## Các họ trang

- **Trang chủ:** ảnh phụ kiện laptop làm điểm nhìn; phần mô tả rõ hiện trạng, nhóm hàng định hướng và lời mời tạo tài khoản. Không tạo product card giả.
- **Tài khoản:** tiêu đề ngắn, form là trọng tâm, thông tin hỗ trợ đặt cạnh form trên desktop; trên mobile ưu tiên form và ẩn nội dung hỗ trợ trùng lặp.
- **Dashboard:** số liệu hoặc trạng thái thật, không mô phỏng doanh thu/đơn hàng.
- **Category:** danh sách dễ quét và form rõ nhãn, lỗi, trạng thái.

## Thành phần dùng chung

Header, wordmark, footer, button, form, focus ring và thông báo dùng chung token. Mọi trang hỗ trợ 320px trở lên; link và nút không xuống dòng. Lỗi biểu mẫu hiển thị cạnh trường; tên người dùng luôn được Blade escape.

## Ranh giới

Thiết kế này không tạo route, migration, package hay dữ liệu sản phẩm. Khi catalog công khai và checkout được triển khai, mở rộng cùng hệ thống thay vì thêm màn giả.

## Exports

Tệp gốc 'tokens.css' là nguồn token đầy đủ cho CSS/Vite. Các ánh xạ sau chỉ phục vụ việc mang thiết kế sang công cụ khác; chúng không cài thêm framework vào dự án.

### CSS

```css
@import './tokens.css';
/* Dùng var(--color-paper), var(--color-ink), var(--color-accent),
   var(--font-display), var(--font-body) và thang khoảng cách có tên. */
```

### Tailwind v4

```css
@theme {
  --color-paper: oklch(98% 0.006 93);
  --color-ink: oklch(20% 0.018 255);
  --color-accent: oklch(45% 0.19 264);
  --font-display: Bahnschrift, "Arial Narrow", "Segoe UI", sans-serif;
  --font-body: "Segoe UI", Tahoma, sans-serif;
}
```

### DTCG

```json
{"color":{"paper":{"$value":"oklch(98% 0.006 93)","$type":"color"},"ink":{"$value":"oklch(20% 0.018 255)","$type":"color"},"accent":{"$value":"oklch(45% 0.19 264)","$type":"color"}}}
```

### shadcn/ui

```css
:root {
  --background: 98% 0.006 93;
  --foreground: 20% 0.018 255;
  --primary: 45% 0.19 264;
  --primary-foreground: 99% 0.003 255;
}
```
