# Thiết kế giao diện — Trạm Phụ Kiện

## Định hướng

Website bán phụ kiện máy tính với cảm giác cửa hàng công nghệ gọn, rõ và đáng tin. Giao diện chỉ mô tả những chức năng đã có, dùng dữ liệu và trạng thái thật. Hành trình khách hàng hiện bao gồm khám phá sản phẩm, giỏ hàng, báo giá, COD hoặc VNPay Sandbox, theo dõi đơn và trò chuyện hỗ trợ.

## Hệ thống chung

- **Thể loại:** modern minimal cho retail công nghệ.
- **Màu:** giấy sáng hơi ấm, mực graphite và ultramarine hiện có làm điểm nhấn. Dùng token trong `tokens.css` cho mọi màu.
- **Chữ:** Bahnschrift cho tiêu đề và số; Segoe UI cho nội dung. Không dùng chữ nghiêng ở tiêu đề.
- **Nhịp:** lưới đều, đường kẻ mảnh, bề mặt sáng và khoảng trắng có chủ đích. Khu vực quản trị ưu tiên mật độ dữ liệu; storefront ưu tiên sản phẩm, giá, tồn kho và thao tác mua thật.
- **Bề mặt:** nền giấy trung tính cho canvas, bề mặt sáng cho card và form, viền xám rõ nhưng nhẹ. Ultramarine dùng cho CTA chính, trạng thái focus và điểm định hướng; không dùng mảng tối lớn chỉ để trang trí.
- **Kiến trúc:** storefront dùng macrostructure Catalogue cho khám phá và Workbench cho giỏ hàng, thanh toán, đơn hàng, hỗ trợ. Header ngang gọn và footer inline dùng xuyên suốt hành trình khách hàng.
- **Chuyển động:** chỉ phản hồi hover/active ngắn; hỗ trợ reduced motion.
- **Điều hướng:** giữ các route và quyền hiện tại, nút dẫn chỉ tới chức năng đã tồn tại.

## Các họ trang

- **Trang chủ:** hero hai cột vừa phải với ảnh phụ kiện thật, một CTA mua sắm chính và lợi ích có thể kiểm chứng. Phần dưới giải thích ngắn hành trình mua hiện có; không dùng card sản phẩm hoặc số liệu giả.
- **Tài khoản:** tiêu đề ngắn, form là trọng tâm, thông tin hỗ trợ đặt cạnh form trên desktop và chuyển xuống sau form ở màn hẹp.
- **Dashboard:** số liệu hoặc trạng thái thật, không mô phỏng doanh thu/đơn hàng.
- **Category/Brand:** danh sách dễ quét và form rõ nhãn, lỗi, trạng thái.
- **Catalog công khai:** cấu trúc hai cột với bộ lọc gọn và lưới sản phẩm dạng retail card; ảnh, tên, giá, tồn kho và CTA có thứ bậc rõ. Desktop dùng ba cột khi đủ chỗ, tablet hai cột, mobile một cột.
- **Chi tiết sản phẩm:** gallery chiếm vùng rộng hơn, panel mua hàng có giá và tồn kho nổi bật, quantity và CTA dùng được bằng bàn phím; không che giấu trạng thái hết hàng.
- **Product Admin:** workbench mật độ vừa, hàng dữ liệu thay cho card dashboard; form chia thông tin và media, thư viện ảnh có thứ tự/primary rõ ràng.
- **Inventory:** workbench bảng dữ liệu thật, badge còn/sắp hết/hết hàng, form tác vụ tách khỏi Product, ledger dạng timeline chỉ đọc và danh sách phê duyệt có trạng thái bằng chữ.
- **Cart:** bố cục danh sách sản phẩm và summary bất đối xứng, giá/tồn khả dụng từ dữ liệu thật, quantity dùng được bằng bàn phím, cảnh báo item không hợp lệ và empty state có đường về cửa hàng.
- **Checkout:** Workbench hai vùng, form giao hàng là nội dung chính và summary sticky trên laptop. Báo giá, coupon, COD và VNPay Sandbox dùng đúng trạng thái thật, error bag và request key riêng; không diễn giải cơ chế nội bộ cho khách hàng.
- **Order:** danh sách có bộ lọc và card dễ quét; chi tiết đơn ưu tiên sản phẩm, trạng thái, người nhận, timeline và tổng tiền. Yêu cầu hủy chỉ xuất hiện đúng điều kiện nghiệp vụ hiện có.
- **Support Chat:** trang vào hỗ trợ và widget dùng chung một hội thoại thật; composer luôn tiếp cận được, nội dung động escape và trạng thái đóng/mở không che thao tác chính ở màn hẹp.
- **Shipping Rate:** workbench quản trị hai vùng cố định, nhấn mạnh mức phí VND thật và form chỉnh sửa đơn trường; không mô phỏng Checkout hoặc ưu đãi.
- **Coupon Definition:** ledger quản trị mã và editor target theo scope trong workspace; không hiển thị lượt dùng giả hoặc mô phỏng luồng Apply Coupon/Checkout.
- **Admin/Employee Workspace:** sidebar trái theo role, topbar gọn và main workbench rộng; desktop cố định, mobile dùng drawer có overlay, Escape, focus trap và scroll lock. Storefront tiếp tục dùng header ngang.

### Admin/Employee Workspace

- Dùng macrostructure **Workbench**: rail graphite 15,5rem, topbar ngữ cảnh 4,25rem và canvas sáng rộng tối đa 88rem. Laptop 1366–1440px phải thấy heading, bộ lọc và phần đầu dữ liệu mà không cần đi qua hero lớn.
- Nhóm điều hướng theo công việc thật: tổng quan, bán hàng, hàng hóa, thiết lập bán hàng và cửa hàng. Active state dùng ultramarine; các liên kết không được phép theo role không xuất hiện.
- Heading trang cao vừa phải, mô tả một câu tập trung vào nhiệm vụ. Không dùng nội dung “đã triển khai”, “chưa triển khai”, tên phase/foundation hoặc giải thích cơ chế nội bộ.
- Bảng và ledger là bề mặt dữ liệu chính: header tương phản nhẹ, hàng có nhịp 48–56px, tên/mã/trạng thái và thao tác có thứ bậc rõ. Chỉ chuyển sang card khi màn hình không còn đủ chỗ; không giấu thao tác chính.
- Form dùng một bề mặt sáng, chia section bằng đường kẻ thay vì card lồng nhau. Nhãn, helper/error text và focus ring dùng foundation chung; nhóm nút luôn giữ hành động chính dễ thấy.
- Không hiển thị internal ID, request/event key, fingerprint hoặc raw JSON. Chỉ trình bày mã nghiệp vụ cần cho vận hành như mã đơn, SKU và mã giao dịch cổng thanh toán.

## Thành phần dùng chung

Storefront header, workspace sidebar/topbar, wordmark, footer, button, form, focus ring và thông báo dùng chung token. Control tương tác có chiều cao tối thiểu 44px, focus nhìn thấy rõ, giá dùng số tabular. Mọi trang hỗ trợ 320px trở lên và không tràn ngang; lỗi/helper text nằm cạnh trường; nội dung động luôn được Blade escape.

## Ranh giới

Visual system này bao phủ Storefront, Catalog, Product Detail, Authentication, Cart, Checkout, VNPay Return, Customer Order và Support Chat mà không thêm package hay dữ liệu giả. UI không thay đổi route, quyền, request key, idempotency, error bag hoặc nghiệp vụ phía server. VNPay trên giao diện hiện được mô tả rõ là Sandbox; Refund, Review và các chức năng chưa có route khách hàng không được dựng CTA giả.

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
