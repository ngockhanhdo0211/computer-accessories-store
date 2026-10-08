# 02 — Quy tắc nghiệp vụ

Các từ khóa trạng thái/mã dưới đây là giá trị nghiệp vụ đã chốt. Không đổi tên hay tự mở rộng khi triển khai.

## Tài khoản và quyền

- Role: `customer`, `employee`, `admin`. Status: `active`, `locked`, `inactive`. Hạng: `dong`, `bac`, `vang`, `kim_cuong`. Role và hạng độc lập.
- Chỉ customer tự đăng ký. Admin tạo employee; không mở đăng ký admin công khai. Không khóa hoặc hạ quyền admin cuối cùng.
- Mật khẩu luôn hash. Không xóa cứng tài khoản đã phát sinh dữ liệu. Phân quyền kiểm tra tại backend và theo quyền sở hữu dữ liệu; thao tác nhạy cảm lưu audit log.
- `locked` là khóa tạm, `inactive` là ngừng sử dụng. Cả hai từ chối đăng nhập và thu hồi session. Admin có thể mở `locked`; password reset không tự mở khóa. `inactive` không tự khôi phục, chỉ admin kích hoạt lại.
- Admin production đầu tiên được bootstrap bằng lệnh Artisan nội bộ `app:promote-customer-to-admin {email}`: chỉ nâng một Customer hiện hữu, `active` thành Admin trong transaction có khóa hàng và audit; không tạo tài khoản/mật khẩu, không hard-code credential và không có endpoint công khai. Lệnh yêu cầu xác nhận rõ trong production và replay chỉ thành công khi audit evidence nhất quán. Factory chỉ phục vụ test.
- Employee production được bootstrap bằng lệnh Artisan nội bộ `app:promote-customer-to-employee {email}` theo cùng nguyên tắc: chỉ nâng một Customer hiện hữu đang `active`, khóa hàng và ghi audit hệ thống nguyên tử; không tạo hoặc đổi mật khẩu, không có endpoint công khai. Production bắt buộc xác nhận tương tác và replay chỉ hợp lệ khi role cùng đúng một audit evidence nhất quán.

## Catalog

- Sản phẩm có SKU và slug unique, tên, mô tả ngắn/chi tiết, giá, giá khuyến mãi, bắt buộc thuộc một category và một brand, có ảnh và tồn kho. Các slug của catalog phải unique. Có một ảnh đại diện và nhiều ảnh chi tiết.
- Giá khuyến mãi (nếu có) nhỏ hơn giá gốc. Trạng thái hiển thị `active`/`hidden` độc lập với tình trạng kho `con_hang`/`sap_het`/`het_hang`; tình trạng kho tính tự động từ tồn khả dụng và `low_stock_threshold`.
- Sản phẩm hết hàng vẫn xem được nhưng không thể mua. Không xóa cứng sản phẩm có dữ liệu nghiệp vụ.
- Danh mục quản lý động, hỗ trợ cha-con; cấm tự làm cha hoặc tạo chu trình. Không xóa category/brand đang được dùng, không tự động xóa sản phẩm liên quan. Ưu tiên ẩn dữ liệu đã phát sinh nghiệp vụ thay vì xóa.
- Category/brand bị ẩn vẫn giữ quan hệ với product, nhưng product liên quan không hiển thị hoặc bán công khai; admin vẫn quản lý được. Cảnh báo số product bị ảnh hưởng trước khi ẩn. Muốn bán lại phải kích hoạt category/brand hoặc chuyển product sang đối tượng đang hoạt động.

## Hỗ trợ khách hàng

- Mỗi Customer có tối đa một hội thoại hỗ trợ riêng. Customer chỉ xem/gửi trong hội thoại của mình; Admin và Employee active dùng chung hộp thư và đều có thể trả lời.
- Tin nhắn plain text tối đa 2.000 ký tự, append-only, không sửa/xóa và không có attachment trong MVP. Không hỗ trợ anonymous chat.
- Mỗi người có read marker riêng; tin tự gửi không tính unread. Hội thoại đã đóng được tự mở lại khi Customer hoặc staff gửi tin mới.
- Foundation dùng incremental polling khoảng 5 giây khi màn chat đang mở, chỉ lấy message ID mới hơn; đây không phải realtime tuyệt đối và chưa dùng WebSocket/Reverb/Pusher.
- Retention, export, attachment và moderation nằm ngoài MVP cho tới khi có contract riêng.

## Tồn kho

- Không sửa số lượng kho tùy tiện trong form sản phẩm. Mọi thay đổi phải có inventory transaction loại `import`, `sale`, `cancel_restore`, `damaged`, `manual_adjustment`.
- Phiên bản đầu chỉ có một kho. Mỗi sản phẩm có `low_stock_threshold`, mặc định 5. Tồn khả dụng = tồn bán được trừ số lượng trong `stock_reservations` còn hiệu lực; không cho tồn khả dụng âm. Hàng hỏng tách khỏi tồn bán được; tình trạng `sap_het` tính theo tồn khả dụng và ngưỡng sản phẩm.
- Reservation VNPay giữ hàng 15 phút, được giải phóng khi thanh toán thất bại hoặc hết hạn. Giữ/giải phóng là thay đổi khả dụng, không phải nhập/xuất tồn vật lý; khi chuyển reservation thành sale phải ghi inventory transaction đúng một lần.
- Hàng hoàn đang chờ kiểm tra không được cộng tồn khả dụng. Sau kiểm tra, hàng còn bán được mới hoàn vào tồn bán được; hàng hỏng chuyển vào `damaged_quantity`. Employee tạo đề nghị `manual_adjustment`, admin duyệt; admin điều chỉnh trực tiếp vẫn cần lý do và audit log.
- Tạo đơn trừ kho đúng một lần, hủy đơn hoàn kho đúng một lần, giao thành công tăng số đã bán đúng một lần. Các bước đặt hàng/kho dùng transaction và locking phù hợp để chống oversell, callback hay request lặp.

## Checkout và đơn

- Backend kiểm tra lại sản phẩm, giá, tồn kho, mã giảm giá; phí vận chuyển do server tính. Khách nhập người nhận, chọn COD/VNPay và xem bước xác nhận trước khi đặt. Chống tạo đơn trùng.
- Khi đơn được tạo hợp lệ, lưu order, order items, biến động kho, lượt dùng mã, trạng thái và cập nhật giỏ trong transaction. Với COD, thực hiện khi xác nhận đặt đơn; với VNPay, chỉ thực hiện sau khi xác minh thành công và chuyển reservation thành xuất kho. Order item lưu snapshot tên, SKU, giá và phần discount được phân bổ lúc mua; order lưu snapshot người nhận và ưu đãi.
- Customer không tự đổi trạng thái Order. Customer active chỉ được gửi đúng một yêu cầu hủy cho Order của chính mình khi Order còn `da_dat`; Admin/Employee active mới approve hoặc reject. Khi request còn `pending`, mọi xử lý làm Order tiếp tục rời `da_dat` bị chặn cho đến khi staff approve hoặc reject. Request pending không đổi Order, inventory, payment, Coupon Usage hay Refund; request approved/rejected là terminal, không rút lại, sửa lý do hoặc gửi lại trong MVP. Với dữ liệu drift cũ có request pending nhưng Order đã rời `da_dat`, không được approve; Admin/Employee active chỉ được reject với ghi chú bắt buộc, actor, thời điểm review và audit trong cùng transaction. Employee/Admin vẫn có luồng staff cancellation riêng theo điều kiện lifecycle hiện có. Không xóa đơn; xem [04-order-lifecycle.md](04-order-lifecycle.md).
- Màn hình quản lý đơn hỗ trợ tra cứu, xem chi tiết và các transition đã triển khai. Workspace Admin/Employee có danh sách và chi tiết Customer Cancellation Request, nhưng Employee không được kích hoạt Refund gateway/reconciliation.

## Tiền

- Phiên bản đầu chỉ dùng VND, không có thuế tách riêng. Lưu tiền bằng số nguyên, tuyệt đối không dùng float. Server tính lại toàn bộ giá hàng, discount, phí vận chuyển và tổng tiền.
- Discount sản phẩm phân bổ tỷ lệ theo `eligible line subtotal`; làm tròn xuống từng VND, sau đó phân phối phần dư bằng largest remainder method. Khi phần dư bằng nhau, ưu tiên `order_item` ID nhỏ hơn. `free_shipping` giảm phí vận chuyển, không phân bổ xuống item.
- Phí vận chuyển: Hà Nội 30.000 VND, tỉnh/thành khác 45.000 VND. Không tự động miễn phí theo tổng đơn. Mức phí nằm trong cấu hình/dữ liệu tập trung, không hard-code rải rác.

## Thanh toán

- Phương thức: `cod`, `vnpay`. Trạng thái thanh toán: `chua_thanh_toan`, `da_thanh_toan`, `that_bai`, `hoan_tien`, độc lập với vận chuyển.
- COD tạo đơn hợp lệ ở `chua_thanh_toan`; chỉ ghi `da_thanh_toan` khi giao thành công. Khi COD bị hủy, `order_status` chuyển `da_huy` nhưng `payment_status` giữ nguyên `chua_thanh_toan`, không đổi thành `that_bai`, vì chưa có giao dịch thanh toán thất bại.
- Trước khi chuyển sang VNPay, tạo `payment_attempt` và `stock_reservation` 15 phút, chưa tạo order chính thức. Tồn khả dụng trừ reservation còn hiệu lực. Attempt mới snapshot từng Cart line gồm định danh/timestamp Cart, giá, subtotal, discount phân bổ bằng largest remainder và line total; callback tạo Order chỉ từ snapshot bất biến, không đọc lại Cart, giá hoặc Coupon targets hiện tại.
- Khởi tạo VNPay dùng `payment_attempts.gateway_reference` làm `vnp_TxnRef`; attempt mới dùng `PA` cộng UUID viết hoa đã bỏ dấu gạch ngang. IP khởi tạo được snapshot một lần từ request server và replay dùng lại IP, reference, thời điểm tạo/hết hạn cũ. Attempt hết hạn hoặc không còn `chua_thanh_toan` không được sinh URL mới.
- VNPay Payment Initiation chỉ hỗ trợ Sandbox protocol `2.1.0`. Return URL công khai chỉ hiển thị trạng thái đang xác minh và tuyệt đối không cập nhật thanh toán. IPN công khai dùng GET, xác minh raw query/chữ ký HMAC-SHA512, merchant, reference và amount rồi trả JSON HTTP 200 theo mã VNPay; không lưu raw callback, signature hay secret.
- Nếu callback thành công sau khi reservation hết hạn/được giải phóng: kiểm tra lại tồn khả dụng và Coupon capacity trong transaction. Cả hai còn đủ thì tạo một order `da_dat` và xuất kho đúng một lần; thiếu một trong hai thì không tạo order, lưu payment đã thành công và tạo full refund `pending` liên kết `payment_attempt`. Snapshot lịch sử không có line discount vẫn tạo Order khi discount tổng bằng 0 hoặc chỉ có một line; nhiều line với discount dương tạo Refund `snapshot_incomplete`. Snapshot hỏng là lỗi kỹ thuật, rollback và trả `99`, không tự tạo Refund.
- Cart chỉ bị xóa line khi `cart_item_id`, Customer, Product, quantity và `updated_at` cùng khớp snapshot; line đã thay đổi hoặc attempt lịch sử thiếu metadata được giữ nguyên. Callback thanh toán thất bại hợp lệ chuyển attempt `that_bai`, giải phóng stock/Coupon, giữ Cart và không tạo Order/Refund/ledger.
- Refund có `pending`, `succeeded`, `failed`, có thể gắn `payment_attempt` khi chưa có order. Refund chỉ được rời `pending` một lần sang `succeeded` hoặc `failed`; trạng thái terminal không quay lại `pending` và không đổi qua lại. Phiên bản đầu chỉ full refund; không hoàn vượt số đã thanh toán và không xử lý refund thành công hai lần. Với order VNPay đã thanh toán bị hủy, trong khi refund chờ/thất bại, `payment_status` vẫn `da_thanh_toan`; chỉ chuyển `hoan_tien` khi refund thành công.
- Gửi Refund ra VNPay theo **at-most-once**: mỗi Refund có tối đa một HTTP request và Request ID `RF` + 30 ký tự hexadecimal uppercase, không automatic retry. Evidence `submitted` chỉ được Admin chuyển sang `ambiguous` sau operational lease lấy từ cấu hình server đã validate (mặc định 120 giây, luôn dài hơn HTTP timeout), không nhận thời gian từ client. Response có chữ ký hợp lệ của chính Request ID đó đến sau stale mark vẫn được phép chốt `ambiguous → succeeded/failed` mà không gửi request mới. Chỉ response đã xác minh đồng thời `vnp_ResponseCode=00` và `vnp_TransactionStatus=00` mới thành công tự động. Lỗi truyền tải, response không xác minh được, trạng thái `05`/`06` hoặc mã `94`/`98`/`99` đều là `ambiguous`: Refund giữ `pending`, Payment Attempt giữ `da_thanh_toan`, Coupon chưa release và Admin phải đối soát thủ công trên VNPay Merchant Portal. MVP không dùng `querydr` làm final authority cho Refund.

## Hủy và hoàn kho

- Customer Cancellation Request chỉ tồn tại ở `da_dat`: khi approve, không tạo Return Inspection giả; toàn bộ quantity từng item hoàn vào sellable bằng đúng một `cancel_restore` liên kết request, `damaged_delta = 0`, `sold_quantity` không đổi. Luồng staff cancellation có hàng quay lại vẫn dùng Return Inspection: đơn đang trung chuyển không hủy trực tiếp; sau khi hàng quay lại và được kiểm tra, admin mới chuyển `da_huy`. Hàng chờ kiểm tra không vào tồn khả dụng; hàng tốt về tồn bán được, hàng hỏng vào `damaged_quantity`.
- Approve Customer Cancellation Request cho VNPay đã thanh toán tạo đúng một full Refund `pending` reason `customer_cancellation`, không gọi gateway trong transaction. Payment Attempt và Coupon Usage vẫn lần lượt `da_thanh_toan`/`consumed` cho đến khi Refund Processing xác nhận thành công; COD approve không tạo Refund và release Coupon Usage ngay.

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

Chi tiêu xét hạng là tiền sản phẩm sau discount của order `da_giao`, không gồm shipping: trên snapshot Order hiện tại là `items_subtotal_vnd - item_discount_vnd`. Product promotion đã nằm trong giá snapshot; cart/product/category/brand coupon làm giảm `item_discount_vnd`; `free_shipping` chỉ giảm shipping nên không đổi chi tiêu xét hạng. Full refund loại toàn bộ giá trị order khỏi chi tiêu. Hạng cập nhật tự động; membership history chỉ ghi khi hạng thay đổi, không ghi một dòng cho mỗi lần tổng chi tiêu tăng nhưng vẫn ở cùng hạng. Admin không sửa hạng trực tiếp, chỉ yêu cầu recalculation có audit log. Hạng dùng để xét điều kiện mã giảm giá, chưa tự động giảm giá toàn hệ thống.
