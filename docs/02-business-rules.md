# 02 — Quy tắc nghiệp vụ

Các từ khóa trạng thái/mã dưới đây là giá trị nghiệp vụ đã chốt. Không đổi tên hay tự mở rộng khi triển khai.

## Tài khoản và quyền

- Role: `customer`, `employee`, `admin`. Status: `active`, `locked`, `inactive`. Hạng: `dong`, `bac`, `vang`, `kim_cuong`. Role và hạng độc lập.
- Chỉ customer tự đăng ký. Admin tạo employee; không mở đăng ký admin công khai. Không khóa hoặc hạ quyền admin cuối cùng.
- Mật khẩu luôn hash. Không xóa cứng tài khoản đã phát sinh dữ liệu. Phân quyền kiểm tra tại backend và theo quyền sở hữu dữ liệu; thao tác nhạy cảm lưu audit log.
- `locked` là khóa tạm, `inactive` là ngừng sử dụng. Cả hai từ chối đăng nhập và thu hồi session. Admin có thể mở `locked`; password reset không tự mở khóa. `inactive` không tự khôi phục, chỉ admin kích hoạt lại.
- Admin đầu tiên dự kiến tạo bằng lệnh Artisan tương tác `app:create-admin`: kiểm tra email trùng và hash password; không hard-code admin/password trong repository. Factory chỉ phục vụ test.

## Catalog

- Sản phẩm có SKU và slug unique, tên, mô tả ngắn/chi tiết, giá, giá khuyến mãi, bắt buộc thuộc một category và một brand, có ảnh và tồn kho. Các slug của catalog phải unique. Có một ảnh đại diện và nhiều ảnh chi tiết.
- Giá khuyến mãi (nếu có) nhỏ hơn giá gốc. Trạng thái hiển thị `active`/`hidden` độc lập với tình trạng kho `con_hang`/`sap_het`/`het_hang`; tình trạng kho tính tự động từ tồn khả dụng và `low_stock_threshold`.
- Sản phẩm hết hàng vẫn xem được nhưng không thể mua. Không xóa cứng sản phẩm có dữ liệu nghiệp vụ.
- Danh mục quản lý động, hỗ trợ cha-con; cấm tự làm cha hoặc tạo chu trình. Không xóa category/brand đang được dùng, không tự động xóa sản phẩm liên quan. Ưu tiên ẩn dữ liệu đã phát sinh nghiệp vụ thay vì xóa.
- Category/brand bị ẩn vẫn giữ quan hệ với product, nhưng product liên quan không hiển thị hoặc bán công khai; admin vẫn quản lý được. Cảnh báo số product bị ảnh hưởng trước khi ẩn. Muốn bán lại phải kích hoạt category/brand hoặc chuyển product sang đối tượng đang hoạt động.

## Tồn kho

- Không sửa số lượng kho tùy tiện trong form sản phẩm. Mọi thay đổi phải có inventory transaction loại `import`, `sale`, `cancel_restore`, `damaged`, `manual_adjustment`.
- Phiên bản đầu chỉ có một kho. Mỗi sản phẩm có `low_stock_threshold`, mặc định 5. Tồn khả dụng = tồn bán được trừ số lượng trong `stock_reservations` còn hiệu lực; không cho tồn khả dụng âm. Hàng hỏng tách khỏi tồn bán được; tình trạng `sap_het` tính theo tồn khả dụng và ngưỡng sản phẩm.
- Reservation VNPay giữ hàng 15 phút, được giải phóng khi thanh toán thất bại hoặc hết hạn. Giữ/giải phóng là thay đổi khả dụng, không phải nhập/xuất tồn vật lý; khi chuyển reservation thành sale phải ghi inventory transaction đúng một lần.
- Hàng hoàn đang chờ kiểm tra không được cộng tồn khả dụng. Sau kiểm tra, hàng còn bán được mới hoàn vào tồn bán được; hàng hỏng chuyển vào `damaged_quantity`. Employee tạo đề nghị `manual_adjustment`, admin duyệt; admin điều chỉnh trực tiếp vẫn cần lý do và audit log.
- Tạo đơn trừ kho đúng một lần, hủy đơn hoàn kho đúng một lần, giao thành công tăng số đã bán đúng một lần. Các bước đặt hàng/kho dùng transaction và locking phù hợp để chống oversell, callback hay request lặp.

## Checkout và đơn

- Backend kiểm tra lại sản phẩm, giá, tồn kho, mã giảm giá; phí vận chuyển do server tính. Khách nhập người nhận, chọn COD/VNPay và xem bước xác nhận trước khi đặt. Chống tạo đơn trùng.
- Khi đơn được tạo hợp lệ, lưu order, order items, biến động kho, lượt dùng mã, trạng thái và cập nhật giỏ trong transaction. Với COD, thực hiện khi xác nhận đặt đơn; với VNPay, chỉ thực hiện sau khi xác minh thành công và chuyển reservation thành xuất kho. Order item lưu snapshot tên, SKU, giá và phần discount được phân bổ lúc mua; order lưu snapshot người nhận và ưu đãi.
- Customer chỉ hủy đơn của mình ở `da_dat`. Employee hủy `da_dat` hoặc `cho_chuyen_phat` khi có lý do. Không hủy trực tiếp đơn `dang_trung_chuyen`; chỉ admin chuyển sang `da_huy` sau khi xác nhận hàng quay lại. Không xóa đơn; xem [04-order-lifecycle.md](04-order-lifecycle.md).
- Màn hình quản lý đơn hỗ trợ tra cứu, xem chi tiết và trong Order Transit Progression Phase 1 cho Admin/Employee thực hiện hai bước tiến vận chuyển đã chốt. Tra cứu theo mã đơn, tài khoản đặt, người nhận, email, điện thoại, địa chỉ; lọc theo trạng thái vận chuyển/thanh toán, phương thức thanh toán, khoảng thời gian; có phân trang và mặc định mới nhất trước. Các thao tác giao thành công và hủy vẫn thuộc slice tương lai.

## Tiền

- Phiên bản đầu chỉ dùng VND, không có thuế tách riêng. Lưu tiền bằng số nguyên, tuyệt đối không dùng float. Server tính lại toàn bộ giá hàng, discount, phí vận chuyển và tổng tiền.
- Discount sản phẩm phân bổ tỷ lệ theo `eligible line subtotal`; làm tròn xuống từng VND, sau đó phân phối phần dư bằng largest remainder method. Khi phần dư bằng nhau, ưu tiên `order_item` ID nhỏ hơn. `free_shipping` giảm phí vận chuyển, không phân bổ xuống item.
- Phí vận chuyển: Hà Nội 30.000 VND, tỉnh/thành khác 45.000 VND. Không tự động miễn phí theo tổng đơn. Mức phí nằm trong cấu hình/dữ liệu tập trung, không hard-code rải rác.

## Thanh toán

- Phương thức: `cod`, `vnpay`. Trạng thái thanh toán: `chua_thanh_toan`, `da_thanh_toan`, `that_bai`, `hoan_tien`, độc lập với vận chuyển.
- COD tạo đơn hợp lệ ở `chua_thanh_toan`; chỉ ghi `da_thanh_toan` khi giao thành công. Khi một COD bị hủy trong slice cancellation tương lai, `order_status` chuyển `da_huy` nhưng `payment_status` giữ nguyên `chua_thanh_toan`, không đổi thành `that_bai`, vì chưa có giao dịch thanh toán thất bại.
- Trước khi chuyển sang VNPay, tạo `payment_attempt` và `stock_reservation` 15 phút, chưa tạo order chính thức. Tồn khả dụng trừ reservation còn hiệu lực. Khi xác minh chữ ký, số tiền và mã giao dịch thành công trong hạn, tạo một order `da_dat` và chuyển reservation thành xuất kho. Callback idempotent, chỉ tạo một order và xử lý kho một lần.
- Nếu callback thành công sau khi reservation hết hạn/được giải phóng: kiểm tra lại tồn khả dụng trong transaction. Còn đủ thì tạo một order `da_dat` và xuất kho đúng một lần; không đủ thì không tạo order, lưu payment đã thành công và tạo refund `pending` liên kết `payment_attempt`. Thất bại hoặc hết hạn giải phóng reservation; thanh toán thất bại không trừ kho, xóa giỏ hay tiêu thụ mã.
- Refund có `pending`, `succeeded`, `failed`, có thể gắn `payment_attempt` khi chưa có order. Phiên bản đầu chỉ full refund; không hoàn vượt số đã thanh toán và không xử lý refund thành công hai lần. Với order VNPay đã thanh toán bị hủy, trong khi refund chờ/thất bại, `payment_status` vẫn `da_thanh_toan`; chỉ chuyển `hoan_tien` khi refund thành công.

## Hủy và hoàn kho

- Hủy trước bàn giao: hàng vẫn tại kho được kiểm tra và hoàn vào đúng nhóm tồn ngay một lần. Đơn đang trung chuyển không hủy trực tiếp; sau khi hàng quay lại và được kiểm tra, admin mới chuyển `da_huy` và ghi hoàn kho đúng một lần. Hàng chờ kiểm tra không vào tồn khả dụng; hàng tốt về tồn bán được, hàng hỏng vào `damaged_quantity`.

## Mã giảm giá

- Loại: `percent`, `fixed`, `free_shipping`. Phạm vi chỉ gồm `cart`, `product`, `category`, `brand`; bỏ `all`. Một coupon chỉ có một scope, có thể chọn nhiều target cùng loại; không kết hợp các loại scope trong phiên bản đầu.
- Một đơn chỉ dùng một mã; phiên bản đầu không cộng chồng mã. Kiểm tra thời gian hiệu lực, trạng thái, tổng lượt, lượt mỗi khách, giá trị tối thiểu, hạng thành viên và phạm vi; kiểm tra lại khi giỏ đổi và khi tạo đơn.
- `min_subtotal` tính trên sản phẩm đủ điều kiện theo giá bán thực tế sau product promotion, trước coupon, không gồm shipping. Khi shipping thay đổi, tính lại coupon trước khi tạo order; sau đó giữ snapshot ưu đãi và phí trong order.
- COD hủy toàn bộ trả lượt coupon. VNPay chỉ trả lượt sau khi full refund thành công; refund thất bại không trả lượt. `free_shipping` theo cùng quy tắc. VNPay thất bại không tiêu thụ lượt. Không xóa cứng mã đã dùng.

## Đánh giá

- Chỉ customer đã mua sản phẩm trong order `da_giao` mới được đánh giá; mỗi order item chỉ có một review. Rating là số nguyên từ 1 đến 5.
- Customer chỉ sửa review của chính mình. Review đã duyệt khi bị sửa phải quay lại trạng thái chờ duyệt.
- Review được tạo trong 90 ngày sau `da_giao`, sửa trong 7 ngày sau khi tạo. Customer được soft delete review của mình; admin được approve, hide, soft delete. Full refund và trả hàng khiến review bị ẩn nhưng giữ lịch sử.

## Hạng thành viên

| Hạng | Chi tiêu hợp lệ |
| --- | ---: |
| `dong` | Dưới 5.000.000 VND |
| `bac` | Từ 5.000.000 VND |
| `vang` | Từ 15.000.000 VND |
| `kim_cuong` | Từ 30.000.000 VND |

Chi tiêu xét hạng là tiền sản phẩm sau discount của order `da_giao`, không gồm shipping. Full refund loại toàn bộ giá trị order khỏi chi tiêu. Hạng cập nhật tự động và lưu lịch sử. Admin không sửa hạng trực tiếp, chỉ yêu cầu recalculation có audit log. Hạng dùng để xét điều kiện mã giảm giá, chưa tự động giảm giá toàn hệ thống.
