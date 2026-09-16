# Hướng dẫn làm việc trong dự án

## Bối cảnh

Đây là website bán phụ kiện máy tính và công nghệ. Công nghệ đã chốt: Laravel 12, PHP 8.2, MariaDB/MySQL, Blade, Bootstrap, JavaScript, Vite, PHPUnit và MVC; phát triển trên Windows bằng Visual Studio Code. `package.json` hiện là bộ khởi tạo Vite/Tailwind của Laravel, chưa phải bằng chứng Bootstrap đã được cài. Không tự thêm package để thay đổi công nghệ.

Hiện tại chỉ xây dựng tài liệu nền. Chưa tạo migration, model, controller, giao diện hay chức năng nghiệp vụ nếu chưa có yêu cầu cho một giai đoạn triển khai cụ thể. Đọc [phạm vi](docs/01-project-scope.md), [quy tắc](docs/02-business-rules.md), [ma trận quyền](docs/03-role-permission-matrix.md), [vòng đời đơn](docs/04-order-lifecycle.md), [kế hoạch dữ liệu](docs/05-data-model-plan.md) và [lộ trình](docs/06-development-roadmap.md) trước khi triển khai.

## Kỷ luật phát triển

- Làm từng vertical slice nhỏ. Mỗi slice phải đồng bộ migration, model, validation, authorization, test và tài liệu phù hợp; không xây toàn hệ thống trong một lần.
- Không tự ý đổi quy tắc đã chốt. Nếu tài liệu mâu thuẫn hoặc thiếu quyết định nghiệp vụ ảnh hưởng đến thiết kế, dừng phần phụ thuộc và báo cáo câu hỏi cần chốt.
- Trước và sau mỗi thay đổi, chạy `php artisan test` và báo cáo kết quả. Không dùng `migrate:fresh` hoặc thao tác xóa dữ liệu khi chưa được yêu cầu rõ.
- Giữ controller mỏng; đặt nghiệp vụ nhiều bước trong service/action. Dùng Form Request để validation và Policy/Gate/middleware để phân quyền. Kiểm tra quyền ở backend, không chỉ ẩn nút.
- Phiên bản đầu chỉ dùng VND; lưu và tính tiền bằng số nguyên, không dùng float. Server tính lại toàn bộ tiền. Discount sản phẩm được phân bổ theo eligible line subtotal bằng largest remainder, hòa thì ưu tiên order item ID nhỏ; miễn phí vận chuyển không phân bổ xuống item. Các thao tác nhiều bước về đơn, thanh toán, tồn kho dùng database transaction và locking thích hợp; thiết kế chống xử lý lặp.
- Không thêm package trước khi giải thích lý do. Không đưa `.env`, secret hoặc khóa thanh toán vào Git; không in nội dung `.env` trong báo cáo.
- Không sửa tệp mặc định Laravel nếu không cần thiết. Không xóa cứng tài khoản/sản phẩm/đơn/mã giảm giá đã có dữ liệu nghiệp vụ theo các quy tắc trong tài liệu.

## Quy tắc cốt lõi cần giữ

- Chỉ có ba role `customer`, `employee`, `admin`. Role độc lập với hạng thành viên. Chỉ customer tự đăng ký; employee do admin tạo; không có đăng ký admin công khai; bảo vệ admin cuối cùng.
- Danh mục quản lý động. Nhân viên chỉ xử lý đơn, vận chuyển và tồn kho; không sửa giá hay tổng tiền của đơn và không bỏ qua luồng trạng thái. Admin cũng tuân quy tắc nghiệp vụ.
- Trạng thái đơn và thanh toán tách riêng. Customer chỉ hủy `da_dat`; employee hủy `da_dat`/`cho_chuyen_phat` nếu có lý do; đơn `dang_trung_chuyen` không hủy trực tiếp, chỉ admin chuyển `da_huy` sau xác nhận hàng quay lại. Mọi chuyển trạng thái đúng đồ thị và có lịch sử. Không xóa đơn.
- Mọi biến động kho có `inventory transaction`. Đặt đơn trừ kho, hủy hoàn kho, giao thành công tăng số đã bán, mỗi hiệu ứng đúng một lần. Phiên bản đầu một kho; mỗi sản phẩm có `low_stock_threshold` mặc định 5. Tồn khả dụng = tồn bán được trừ reservation còn hiệu lực và không được âm; hàng hỏng tách khỏi tồn bán được.
- Checkout tính lại giá, kho, giảm giá và phí vận chuyển ở server; lưu snapshot đơn, chi tiết và người nhận. VNPay dùng `payment_attempt` và `stock_reservation` 15 phút trước order. Callback idempotent: sau hạn, còn tồn khả dụng thì tạo đúng một order và xuất kho một lần; thiếu hàng thì không tạo order, ghi payment thành công và refund `pending` gắn attempt.
- Hủy trước bàn giao hoàn kho ngay một lần sau kiểm tra; hàng trung chuyển/chờ kiểm tra không cộng tồn khả dụng, chỉ admin chuyển `da_huy` sau khi hàng quay lại và được kiểm tra. Hàng tốt trả vào tồn bán được, hàng hỏng vào `damaged_quantity`. Refund chỉ toàn phần, không vượt tiền đã trả hay xử lý thành công hai lần.
- Coupon chỉ có scope `cart`, `product`, `category`, `brand`; một scope với nhiều target cùng loại. COD hủy trả lượt; VNPay chỉ trả lượt sau full refund thành công. Thành viên tính tiền sản phẩm sau discount của đơn `da_giao`, không gồm shipping; full refund loại giá trị đơn; hạng tự cập nhật.
- Review cần order `da_giao`, tối đa một review mỗi order item, rating 1–5; tạo trong 90 ngày, sửa trong 7 ngày. Review đã duyệt sửa xong về `pending`; full refund/trả hàng làm review ẩn nhưng giữ lịch sử.
- `locked` và `inactive` đều từ chối đăng nhập và thu hồi session; admin có thể mở khóa/kích hoạt lại. Password reset không tự mở `locked`, `inactive` không tự khôi phục. Category/brand ẩn làm product liên quan không hiển thị/bán công khai.
- Admin đầu tiên dự kiến tạo bằng lệnh Artisan tương tác `app:create-admin` (kiểm tra email trùng, hash password); không hard-code tài khoản/mật khẩu. Factory chỉ dùng cho test. SKU và các slug unique; sản phẩm bắt buộc thuộc đúng một category và một brand.
- Các thao tác nhạy cảm lưu audit log. Tham khảo tài liệu nghiệp vụ để biết chi tiết ràng buộc và điểm còn phải chốt.
