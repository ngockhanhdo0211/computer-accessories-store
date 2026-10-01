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

**Trạng thái triển khai hiện tại:** Order Transit Progression Phase 1 cho Admin/Employee thực hiện `da_dat → cho_chuyen_phat` và `cho_chuyen_phat → dang_trung_chuyen`. COD Order Terminal Lifecycle bổ sung `dang_trung_chuyen → da_giao` cho Admin/Employee và các cạnh đến `da_huy` cho Admin/Employee trước trung chuyển hoặc chỉ Admin khi đang trung chuyển. Mỗi thao tác dùng event key, khóa theo thứ tự ổn định và ghi History/Audit trong cùng transaction. Customer cancel, VNPay terminal lifecycle và Refund vẫn chưa triển khai.

**Return Inspection Foundation:** Đã có chứng từ hai giai đoạn theo từng Order Item. Employee/Admin active được tiếp nhận và hoàn tất kiểm tra ở `da_dat`/`cho_chuyen_phat`; chỉ Admin active được thao tác ở `dang_trung_chuyen`. Foundation chỉ ghi bằng chứng và audit, chưa đổi Order sang `da_huy`, chưa hoàn kho hay tạo inventory transaction.

## Hiệu ứng khi chuyển trạng thái

- Khi đơn hợp lệ được tạo: lưu snapshot order/items/người nhận/ưu đãi và discount phân bổ theo item; ghi trạng thái đầu `da_dat`; trừ kho và ghi `sale` đúng một lần. COD bắt đầu `chua_thanh_toan`. VNPay chỉ tạo order sau khi thanh toán được xác minh thành công và reservation được chuyển thành `sale`.
- Khi hủy COD: sau khi toàn bộ Return Inspection đã completed, mỗi Order Item tạo đúng một ledger `cancel_restore` liên kết Order Item và Return Inspection. Ledger đồng thời tăng `sellable_delta` và `damaged_delta` theo hai kết quả phân loại; tổng hai delta bằng quantity. Không tạo ledger `damaged` riêng và không đổi `sold_quantity`. Nếu đã `dang_trung_chuyen`, chỉ admin được chuyển `da_huy` sau khi hàng quay lại và hoàn tất kiểm tra.
- Khi COD được hủy: `order_status` chuyển `da_huy`, còn `payment_status` giữ `chua_thanh_toan`; không dùng `that_bai` vì COD chưa phát sinh giao dịch thanh toán thất bại. Coupon Usage giữ `order_id` và `consumed_at` làm bằng chứng, đồng thời chuyển sang `released` với thời gian server.
- COD hủy toàn bộ trả lượt coupon. VNPay chỉ trả lượt coupon sau full refund `succeeded`; refund `failed` không trả lượt, kể cả mã `free_shipping`.
- Đơn VNPay đã thanh toán khi hủy tạo refund riêng `pending`; refund có thể `succeeded` hoặc `failed`. Chỉ full refund trong phiên bản đầu; không hoàn vượt tiền đã thanh toán hoặc ghi thành công hai lần. Khi còn `pending`/`failed`, `payment_status` vẫn `da_thanh_toan`; chỉ chuyển `hoan_tien` khi `succeeded`. Refund cũng có thể gắn payment attempt thành công mà chưa có order.
- Khi COD `da_giao`: tăng `sold_quantity` đúng quantity từng Order Item một lần và đổi thanh toán sang `da_thanh_toan`; không đổi sellable/damaged và không tạo inventory ledger mới. Ledger `sale` vật lý đã được ghi khi đặt COD. Hạng dùng tiền sản phẩm sau discount, không shipping; full refund tương lai loại order khỏi chi tiêu và cập nhật hạng tự động.
- Mỗi hiệu ứng quan trọng phải gắn với event key duy nhất toàn cục và được thực hiện trong transaction với locking thích hợp; callback hoặc request lặp không tạo hiệu ứng lặp, còn cùng key trên Order/action/payload khác bị từ chối.

## Nhánh thanh toán VNPay

Luồng thanh toán có trạng thái riêng: `chua_thanh_toan`, `da_thanh_toan`, `that_bai`, `hoan_tien`. Trước khi chuyển VNPay, tạo `payment_attempt` và `stock_reservation` giữ hàng 15 phút; chưa tạo order. Tồn khả dụng trừ reservation còn hiệu lực. Callback phải xác minh chữ ký, số tiền, mã giao dịch và idempotent. Thành công khi reservation còn hiệu lực: tạo đúng một order `da_dat`, chuyển giữ hàng thành sale, tiêu thụ mã và xóa giỏ trong transaction. Thất bại/hết hạn giải phóng reservation; thất bại không trừ kho, xóa giỏ hay tiêu thụ mã.

Callback thành công **sau khi reservation hết hạn**: kiểm tra lại tồn khả dụng trong transaction. Nếu đủ, tạo một order `da_dat`, xuất kho đúng một lần và xử lý coupon/giỏ khi xác lập order. Nếu thiếu, không tạo order; vẫn lưu payment thành công và tạo full refund `pending` liên kết `payment_attempt`. Không tiêu thụ coupon hay xóa giỏ khi không có order. Callback lặp không tạo order, xuất kho hoặc refund thứ hai.

## Màn hình vận hành

Quản lý đơn hàng hỗ trợ tra cứu, xem chi tiết và trong Phase 1 cho Admin/Employee thực hiện đúng hai bước tiến vận chuyển đã nêu; các thao tác giao/hủy chưa xuất hiện. Danh sách hỗ trợ tìm mã đơn, tài khoản đặt, người nhận, email, số điện thoại, địa chỉ; lọc trạng thái vận chuyển/thanh toán, phương thức và khoảng thời gian; phân trang, mới nhất trước.
