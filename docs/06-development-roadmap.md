# 06 — Lộ trình phát triển

Triển khai từng vertical slice nhỏ sau khi các quyết định liên quan trong [05-data-model-plan.md](05-data-model-plan.md) được chốt. Mỗi slice có migration/model, Form Request, Policy/Gate/middleware, service/action nếu nhiều bước, Blade/JavaScript cần thiết và PHPUnit có ý nghĩa; cập nhật tài liệu cùng code. Chạy `php artisan test` trước và sau thay đổi. Không cài package nếu chưa giải thích lý do; không chạy `migrate:fresh` trên dữ liệu cần giữ.

| Bước | Slice dự kiến | Tiêu chí hoàn thành chính |
| --- | --- | --- |
| 0 | Chuẩn bị môi trường | Chọn cấu hình MariaDB/MySQL và cách đưa Bootstrap vào Vite. Không đưa secret vào Git. |
| 1 | Tài khoản và quyền | **Đã hoàn thành phần nền tảng users và đăng ký customer**: migration mở rộng, enum, form/validation, tạo tài khoản an toàn và test SQLite. Đã hoàn thành migration `add_user_domain_checks_to_users_table` với sáu CHECK cho role/status/current_tier, gender, membership_spending và must_change_password; đã xác minh trên MariaDB 10.4.32 và SQLite test. Đã hoàn thành đăng nhập/đăng xuất, kiểm tra active session, giới hạn thử đăng nhập và phân quyền nền tảng ba role với dashboard tối thiểu; test SQLite đạt. Quên/đổi mật khẩu, tạo employee/admin, khóa/mở tài khoản, bảo vệ admin cuối, audit và dashboard nghiệp vụ thuộc các slice tiếp theo. |
| 1.5 | Nền tảng giao diện storefront | **Đã hoàn thành** design system Hallmark, layout Blade dùng chung, trang chủ, đăng ký, đăng nhập và dashboard nền tảng; responsive, accessibility cơ bản, Vite build và test giao diện đã đạt. Catalog, tìm kiếm, giỏ hàng và dashboard nghiệp vụ vẫn chưa hoàn thành. |
| 2 | Catalog | **Đã hoàn thành quản lý Category**: schema cha-con, slug unique, trạng thái hiển thị, CRUD Admin, chống chu trình, chính sách xóa hạn chế và test MariaDB/SQLite. Brand, Product, Product Image và catalog công khai vẫn chưa hoàn thành. |
| 3 | Kho | Một kho; `low_stock_threshold` mặc định 5; tồn khả dụng trừ reservation; hàng hoàn chờ kiểm tra, hàng tốt/hỏng tách; employee đề nghị điều chỉnh, admin duyệt hoặc tự điều chỉnh có lý do/audit. |
| 4 | Giỏ, coupon và shipping | Coupon một scope `cart/product/category/brand`, nhiều target cùng loại; `min_subtotal` sau promotion/trước coupon; phí Hà Nội 30.000, nơi khác 45.000 VND trong cấu hình/dữ liệu. |
| 5 | Checkout COD | VND số nguyên, tính lại tại server; discount item theo largest remainder, `free_shipping` giữ ở shipping; chống trùng, snapshot, kho, usage và giỏ trong transaction. |
| 6 | VNPay | Attempt/reservation 15 phút; callback idempotent. Sau hạn còn hàng tạo một order; thiếu hàng lưu payment thành công và refund `pending` gắn attempt, không tạo order. Không xử lý kho/coupon/giỏ lặp. |
| 7 | Đơn, vận chuyển và refund | Customer chỉ hủy `da_dat`, employee hủy `da_dat`/`cho_chuyen_phat` có lý do; admin chuyển đơn trung chuyển sang hủy sau hàng về/kiểm tra. Chỉ full refund, chống hoàn quá tiền/hai lần; trả coupon theo quy tắc. |
| 8 | Đánh giá, hạng, quản trị | Review tạo trong 90 ngày, sửa trong 7 ngày, soft delete/duyệt/ẩn; full refund/trả hàng ẩn review. Hạng tự tính từ tiền hàng sau discount của đơn đã giao, loại full refund; admin chỉ yêu cầu recalculation có audit. |

Thứ tự có thể điều chỉnh theo phụ thuộc thực tế, nhưng một slice phải được hoàn chỉnh và kiểm thử trước khi mở rộng. Không triển khai toàn bộ lộ trình trong một lần. Các quy tắc đã chốt trong tài liệu có ưu tiên cao hơn suy đoán khi viết code.
