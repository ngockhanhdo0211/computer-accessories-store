# 04 — Vòng đời đơn hàng

## Trạng thái vận chuyển/đơn

```text
da_dat ────────────────> cho_chuyen_phat ───> dang_trung_chuyen ───> da_giao
   │                           │                       │
   └───────────────> da_huy <───┘                       │
                       ^───────────────────────────────┘
```

| Từ | Sang | Điều kiện chính |
| --- | --- | --- |
| `da_dat` | `cho_chuyen_phat` | Đơn hợp lệ, người có quyền xử lý xác nhận bàn giao. |
| `da_dat` | `da_huy` | Customer chỉ hủy đơn mình; employee/admin phải ghi lý do. Kiểm tra hàng tại kho rồi hoàn đúng nhóm tồn một lần. |
| `cho_chuyen_phat` | `dang_trung_chuyen` | Người có quyền cập nhật vận chuyển. |
| `cho_chuyen_phat` | `da_huy` | Employee/admin có lý do; trước bàn giao, kiểm tra hàng tại kho rồi hoàn đúng nhóm tồn một lần. |
| `dang_trung_chuyen` | `da_giao` | Xác nhận giao thành công. |
| `dang_trung_chuyen` | `da_huy` | Không hủy trực tiếp. Chỉ admin chuyển sau khi xác nhận hàng quay lại và được kiểm tra; hàng tốt hoàn bán được, hàng hỏng vào `damaged_quantity`. |

`da_giao` và `da_huy` là trạng thái kết thúc. Cấm nhảy bước, đưa đơn đã giao về trạng thái cũ, tự ý khôi phục đơn đã hủy hoặc xóa đơn. Mỗi lần đổi trạng thái lưu trạng thái cũ, mới, người thực hiện, thời gian và ghi chú/lý do. Trạng thái đơn không được dùng thay trạng thái thanh toán.

**Trạng thái triển khai hiện tại — Order Transit Progression Phase 1:** Admin/Employee chỉ có thể thực hiện `da_dat → cho_chuyen_phat` và `cho_chuyen_phat → dang_trung_chuyen`. Mỗi thao tác khóa Order, dùng event key chống lặp, ghi Order Status History và audit trong cùng transaction. Các cạnh đến `da_giao`/`da_huy`, Customer cancel và mọi hiệu ứng giao/hủy bên dưới vẫn là contract cho slice tương lai, chưa được triển khai.

## Hiệu ứng khi chuyển trạng thái

- Khi đơn hợp lệ được tạo: lưu snapshot order/items/người nhận/ưu đãi và discount phân bổ theo item; ghi trạng thái đầu `da_dat`; trừ kho và ghi `sale` đúng một lần. COD bắt đầu `chua_thanh_toan`. VNPay chỉ tạo order sau khi thanh toán được xác minh thành công và reservation được chuyển thành `sale`.
- Khi hủy trước bàn giao: kiểm tra hàng đang tại kho, ghi `cancel_restore`/`damaged` phù hợp và hoàn tồn bán được ngay đúng một lần cho phần hàng tốt. Nếu đã `dang_trung_chuyen`, hủy/giao thất bại không cộng tồn khả dụng khi hàng còn trên đường hoặc chờ kiểm tra; chỉ admin chuyển `da_huy` sau khi xác nhận hàng quay lại và đã kiểm tra, rồi hoàn hàng tốt hoặc ghi `damaged_quantity` đúng một lần.
- Khi COD được hủy trong slice cancellation tương lai: `order_status` chuyển `da_huy`, còn `payment_status` giữ `chua_thanh_toan`; không dùng `that_bai` vì COD chưa phát sinh giao dịch thanh toán thất bại.
- COD hủy toàn bộ trả lượt coupon. VNPay chỉ trả lượt coupon sau full refund `succeeded`; refund `failed` không trả lượt, kể cả mã `free_shipping`.
- Đơn VNPay đã thanh toán khi hủy tạo refund riêng `pending`; refund có thể `succeeded` hoặc `failed`. Chỉ full refund trong phiên bản đầu; không hoàn vượt tiền đã thanh toán hoặc ghi thành công hai lần. Khi còn `pending`/`failed`, `payment_status` vẫn `da_thanh_toan`; chỉ chuyển `hoan_tien` khi `succeeded`. Refund cũng có thể gắn payment attempt thành công mà chưa có order.
- Khi `da_giao`: tăng số lượng đã bán đúng một lần; COD đổi sang `da_thanh_toan` khi ghi nhận giao thành công. Hạng dùng tiền sản phẩm sau discount, không shipping; full refund loại order khỏi chi tiêu và cập nhật hạng tự động.
- Mỗi hiệu ứng quan trọng phải gắn với định danh đơn/chuyển trạng thái duy nhất và được thực hiện trong transaction với locking thích hợp; callback hoặc request lặp không tạo hiệu ứng lặp.

## Nhánh thanh toán VNPay

Luồng thanh toán có trạng thái riêng: `chua_thanh_toan`, `da_thanh_toan`, `that_bai`, `hoan_tien`. Trước khi chuyển VNPay, tạo `payment_attempt` và `stock_reservation` giữ hàng 15 phút; chưa tạo order. Tồn khả dụng trừ reservation còn hiệu lực. Callback phải xác minh chữ ký, số tiền, mã giao dịch và idempotent. Thành công khi reservation còn hiệu lực: tạo đúng một order `da_dat`, chuyển giữ hàng thành sale, tiêu thụ mã và xóa giỏ trong transaction. Thất bại/hết hạn giải phóng reservation; thất bại không trừ kho, xóa giỏ hay tiêu thụ mã.

Callback thành công **sau khi reservation hết hạn**: kiểm tra lại tồn khả dụng trong transaction. Nếu đủ, tạo một order `da_dat`, xuất kho đúng một lần và xử lý coupon/giỏ khi xác lập order. Nếu thiếu, không tạo order; vẫn lưu payment thành công và tạo full refund `pending` liên kết `payment_attempt`. Không tiêu thụ coupon hay xóa giỏ khi không có order. Callback lặp không tạo order, xuất kho hoặc refund thứ hai.

## Màn hình vận hành

Quản lý đơn hàng hỗ trợ tra cứu, xem chi tiết và trong Phase 1 cho Admin/Employee thực hiện đúng hai bước tiến vận chuyển đã nêu; các thao tác giao/hủy chưa xuất hiện. Danh sách hỗ trợ tìm mã đơn, tài khoản đặt, người nhận, email, số điện thoại, địa chỉ; lọc trạng thái vận chuyển/thanh toán, phương thức và khoảng thời gian; phân trang, mới nhất trước.
