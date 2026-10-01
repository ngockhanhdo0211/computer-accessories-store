# 05 — Kế hoạch mô hình dữ liệu

Đây là bản đồ khái niệm để chuẩn bị thiết kế, **không phải schema/migration cuối cùng**. Chưa tạo bảng thương mại điện tử; đã có migration mở rộng `users` cho đăng ký customer bên cạnh migration Laravel mặc định (`users`, `password_reset_tokens`, `sessions`, cache và queue). `.env.example` đang dùng SQLite mẫu; MariaDB/MySQL là hệ quản trị mục tiêu. Kiểu dữ liệu, khóa, chỉ mục và quan hệ xóa sẽ chốt theo từng slice.

## Nhóm thực thể dự kiến

| Nhóm | Thực thể dự kiến | Quan hệ và bất biến chính |
| --- | --- | --- |
| Tài khoản | users, membership_histories, audit_logs | User có role/status; `locked`/`inactive` từ chối đăng nhập và thu hồi session. Admin mở khóa/kích hoạt lại; password reset không mở khóa. Hạng tự tính, admin chỉ yêu cầu recalculation có audit. Admin đầu tiên dự kiến tạo qua lệnh Artisan tương tác `app:create-admin`; không hard-code, factory chỉ cho test. |
| Catalog | categories, brands, products, product_images | Danh mục cha-con không chu trình; SKU và các slug unique. Product bắt buộc một category và một brand. Category/brand ẩn giữ quan hệ nhưng product liên quan không hiển thị/bán công khai; admin vẫn quản lý, cảnh báo số product ảnh hưởng. |
| Giỏ | carts, cart_items | Thuộc customer; giá/khả dụng phải tính lại lúc checkout, không tin snapshot giỏ. |
| Kho | inventory_balances hoặc trường số lượng thích hợp; inventory_transactions; stock_reservations; adjustment_requests; return_inspections | Một kho. Tồn khả dụng trừ reservation còn hiệu lực, không âm. Hàng chờ kiểm tra không vào tồn khả dụng; hàng tốt hoàn bán được, hàng hỏng vào `damaged_quantity`. Employee đề nghị điều chỉnh, admin duyệt; admin điều chỉnh trực tiếp có lý do/audit. |
| Đơn | orders, order_items, order_status_histories; bằng chứng hàng quay lại/kiểm tra | Order thuộc customer, lưu snapshot người nhận, phí vận chuyển, discount, tổng và mã ưu đãi; item lưu tên, SKU, đơn giá và discount phân bổ. `dang_trung_chuyen` chỉ admin chuyển `da_huy` sau xác nhận hàng về/kiểm tra. Lịch sử bất biến, không xóa đơn. |
| Thanh toán | payments, payment_attempts, refunds | Attempt có trước order VNPay và gắn reservation; payment thành công có thể chưa có order nếu hết hạn giữ hàng và thiếu kho. Refund full `pending/succeeded/failed` có thể liên kết attempt không có order. Ràng buộc một order/attempt, callback idempotent, không hoàn quá tiền đã trả hay ghi refund thành công hai lần. |
| Ưu đãi và vận chuyển | coupons, coupon_targets, coupon_usages; shipping_rates hoặc cấu hình tập trung | Coupon chỉ một scope `cart/product/category/brand`, nhiều target cùng loại. `min_subtotal` lấy giá bán sản phẩm đủ điều kiện sau promotion, trước coupon, không shipping. Phí Hà Nội 30.000 VND, nơi khác 45.000 VND; không miễn tự động theo tổng, không thuế riêng. |
| Đánh giá | reviews | Unique theo order item `da_giao`; rating 1–5. Tạo trong 90 ngày, sửa trong 7 ngày; đã duyệt sửa lại về `pending`. Customer soft delete của mình; admin approve/hide/soft delete; full refund/trả hàng ẩn review, giữ lịch sử. |

## Ràng buộc thiết kế bắt buộc

- Phiên bản đầu chỉ dùng VND dạng số nguyên, không float hoặc thuế tách riêng. Server tính lại tiền. Discount sản phẩm chia tỷ lệ theo eligible line subtotal; làm tròn xuống VND, phân phối dư bằng largest remainder, hòa thì `order_item` ID nhỏ trước. `free_shipping` giảm shipping, không phân bổ item. Snapshot order giữ giá, phí, mã và ưu đãi sau khi tạo.
- COD tạo đơn khi đặt hợp lệ. VNPay tạo `payment_attempt` và `stock_reservation` 15 phút trước khi chuyển cổng, chưa có order. Thành công trong hạn: tạo một order `da_dat`, chuyển reservation thành `sale`, ghi lịch sử, tiêu thụ mã và dọn giỏ trong transaction. Thất bại/hết hạn giải phóng reservation; thất bại giữ giỏ và lượt mã.
- Callback VNPay thành công sau hạn: khóa/kiểm tra lại tồn khả dụng. Đủ hàng thì tạo một order và sale đúng một lần; thiếu hàng thì lưu payment thành công nhưng không tạo order, tạo full refund `pending` gắn attempt. Không tiêu thụ coupon/xóa giỏ khi không có order. Callback lặp không tạo tác dụng lần hai.
- Tồn khả dụng trừ các reservation còn hiệu lực và không âm. Khóa bản ghi kho/mã/reservation cùng định danh attempt khi xử lý đồng thời; ràng buộc duy nhất ngăn hai order cho một attempt. Reservation không phải xuất kho vật lý; `sale` ghi inventory transaction đúng một lần khi chuyển đổi.
- `inventory_transactions` là nguồn truy vết; số dư kho, số đã bán và lượt mã không cập nhật ngoài hành động có log. Hủy trước bàn giao: kiểm tra hàng tại kho và hoàn đúng một lần. Đơn trung chuyển không hủy trực tiếp; admin chỉ chuyển `da_huy` sau khi hàng đã quay lại/được kiểm tra. Hàng chờ kiểm tra không vào tồn khả dụng, hàng tốt hoàn bán được, hàng hỏng vào `damaged_quantity`.
- Refund chỉ toàn phần, không vượt số đã thanh toán hay ghi thành công hai lần. Order VNPay đã trả tiền bị hủy có refund riêng; refund `pending`/`failed` giữ payment `da_thanh_toan`, `succeeded` mới `hoan_tien`. COD hủy toàn bộ trả lượt coupon; VNPay chỉ trả lượt sau full refund thành công, kể cả `free_shipping`.
- Membership spending là tiền sản phẩm sau discount của order `da_giao`, không shipping; full refund loại toàn bộ giá trị order. Hạng tự cập nhật và lưu lịch sử; admin chỉ yêu cầu recalculation có audit, không sửa trực tiếp.
- Trạng thái hiển thị không suy ra từ tồn kho; tình trạng kho được tính tự động theo tồn khả dụng và `low_stock_threshold` của từng product (mặc định 5). Danh mục/thương hiệu đang dùng bị chặn xóa. Tránh cascade xóa dữ liệu lịch sử.
- Mã giao dịch VNPay và callback phải đủ để kiểm tra chữ ký, số tiền, tham chiếu attempt/order tùy trường hợp và idempotency; chỉ lưu trường cần thiết, không lưu secret.
- Cần unique constraint cho SKU, từng loại slug, email, định danh giao dịch VNPay, mỗi order item tối đa một review và mỗi payment attempt tối đa một order. Cần chỉ mục cho khóa ngoại, reservation còn hiệu lực, tra cứu/lọc đơn và lịch sử; kiểm tra hiệu năng khi có dữ liệu thật.

## Câu hỏi còn mở

Không còn câu hỏi nghiệp vụ mở từ danh sách 12 mục đã chốt. Các lựa chọn kỹ thuật cụ thể sẽ được quyết định trong từng vertical slice mà không thay đổi quy tắc ở trên.
