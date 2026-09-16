# 01 — Phạm vi dự án

## Mục tiêu và nền tảng

Website thương mại điện tử chuyên bán phụ kiện máy tính và công nghệ. Nền tảng đã chốt: Laravel 12, PHP 8.2, MariaDB/MySQL, Blade, Bootstrap, JavaScript, Vite, PHPUnit, kiến trúc MVC; môi trường phát triển Windows/Visual Studio Code. Đây là phạm vi dự kiến, chưa phải chức năng đã hiện thực.

Các nhóm hàng ban đầu gồm bàn phím, chuột, tai nghe, loa, webcam, micro, hub, cáp chuyển đổi, USB, thẻ nhớ, ổ cứng, giá đỡ, tay cầm và gaming gear. Nhóm hàng là dữ liệu trong danh mục quản lý động; không hard-code danh mục trong code.

Hệ thống có đúng ba vai trò: `customer`, `employee`, `admin`. Hạng thành viên là thuộc tính ưu đãi của khách hàng, không phải vai trò phân quyền.

## Phạm vi theo người dùng

| Vai trò | Phạm vi |
| --- | --- |
| Customer | Đăng ký/đăng nhập/đăng xuất, hồ sơ, duyệt/tìm/lọc sản phẩm, giỏ hàng, mã giảm giá, checkout COD/VNPay, theo dõi/hủy đơn đủ điều kiện, đánh giá sản phẩm đủ điều kiện. Chỉ xem và thao tác dữ liệu thuộc tài khoản của mình. |
| Employee | Tra cứu và xử lý đơn, cập nhật vận chuyển theo luồng, nghiệp vụ tồn kho. Không quản lý catalog, tài khoản nhân viên hay quyền; không thay đổi giá/tổng tiền đơn. |
| Admin | Quản lý catalog, hình ảnh, danh mục, thương hiệu, đơn, vận chuyển, tồn kho, khách hàng, nhân viên, đánh giá, mã giảm giá, hạng thành viên; xem thống kê và nhật ký. Vẫn phải tuân thủ mọi bất biến nghiệp vụ. |

## Luồng và ranh giới chức năng

- Catalog có sản phẩm thuộc một category và một brand, SKU/slug unique, ảnh đại diện/ảnh chi tiết, danh mục cha-con, trạng thái hiển thị và tình trạng kho độc lập.
- Checkout xác nhận lại dữ liệu và toàn bộ tiền VND ở server, lấy thông tin người nhận, phí vận chuyển, giảm giá và phương thức thanh toán. Đơn lưu snapshot và discount theo từng item để lịch sử không thay đổi khi hồ sơ/catalog đổi.
- VNPay tạo `payment_attempt` và reservation giữ hàng 15 phút trước order. Callback sau hạn còn hàng khả dụng thì tạo một order; thiếu hàng thì lưu payment thành công và refund `pending` mà không tạo order. Phiên bản đầu có một kho; tồn khả dụng trừ reservation còn hiệu lực.
- Vận chuyển dùng vòng đời trạng thái cố định trong [04-order-lifecycle.md](04-order-lifecycle.md). Thanh toán COD/VNPay có trạng thái riêng.
- Tồn kho ghi giao dịch cho mọi thay đổi; khuyến mãi và hạng thành viên tuân [02-business-rules.md](02-business-rules.md).
- Quản lý đơn (tra cứu/chi tiết) và quản lý vận chuyển (đổi trạng thái) là hai màn hình riêng.
- Review chỉ từ order đã giao, tối đa một review mỗi order item, có hạn tạo/sửa và quy trình duyệt/ẩn. Admin đầu tiên dự kiến được tạo qua lệnh Artisan tương tác, không qua đăng ký công khai.

## Hiện trạng repository

Repository đã có vertical slice nền tảng `users` và đăng ký customer: migration mở rộng, enum, form/validation, Action và test. Các bảng thương mại điện tử chưa được tạo; migration Laravel mặc định cho password reset, sessions, cache và queue vẫn giữ nguyên. `.env.example` vẫn là cấu hình SQLite mẫu. `package.json` có Vite và Tailwind từ skeleton, chưa có Bootstrap. Những phần còn thiếu được xử lý theo từng slice phù hợp.

## Ngoài phạm vi giai đoạn tài liệu

Không cài package, đổi cấu hình chạy, tạo schema hay triển khai chức năng. Các quyết định nghiệp vụ đã chốt được tổng hợp trong [05-data-model-plan.md](05-data-model-plan.md).
