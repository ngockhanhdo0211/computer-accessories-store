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
| `da_dat` | `da_huy` | Customer chỉ gửi Cancellation Request; Employee/Admin active approve request pending. Approval hoàn toàn bộ item về sellable đúng một lần, không tạo Return Inspection. Luồng staff cancellation riêng vẫn theo bằng chứng kiểm tra đã chốt. |
| `cho_chuyen_phat` | `dang_trung_chuyen` | Người có quyền cập nhật vận chuyển. |
| `cho_chuyen_phat` | `da_huy` | Employee/admin có lý do; trước bàn giao, kiểm tra hàng tại kho rồi hoàn đúng nhóm tồn một lần. |
| `dang_trung_chuyen` | `da_giao` | Xác nhận giao thành công. |
| `dang_trung_chuyen` | `da_huy` | Không hủy trực tiếp. Chỉ admin chuyển sau khi xác nhận hàng quay lại và được kiểm tra; hàng tốt hoàn bán được, hàng hỏng vào `damaged_quantity`. |

`da_giao` và `da_huy` là trạng thái kết thúc. Cấm nhảy bước, đưa đơn đã giao về trạng thái cũ, tự ý khôi phục đơn đã hủy hoặc xóa đơn. Mỗi lần đổi trạng thái lưu trạng thái cũ, mới, người thực hiện, thời gian và ghi chú/lý do. Trạng thái đơn không được dùng thay trạng thái thanh toán.

**Trạng thái triển khai hiện tại:** Order Transit Progression Phase 1 và COD Order Terminal Lifecycle đã mở các cạnh đã chốt. Customer Cancellation Request cho Order `da_dat` đã có submit/review; approval COD hoặc VNPay là một transaction nhưng VNPay chỉ tạo Refund `pending`, chưa gọi gateway. VNPay Callback/IPN và Refund Processing/Manual Reconciliation tiếp tục xử lý ở boundary riêng.

Customer Cancellation Request `pending` là một chốt vận hành trên Order: transition, staff cancellation hoặc delivery tiếp theo phải đợi request được xử lý. Các writer khóa thống nhất theo thứ tự actor → Order → cancellation request. Dữ liệu drift cũ có request pending trong khi Order đã rời `da_dat` chỉ được reject với ghi chú bắt buộc; không tự reject, không approve và không thay đổi inventory/payment/Coupon/Refund/Membership khi đóng request này.

**Return Inspection Foundation:** Đã có chứng từ hai giai đoạn theo từng Order Item. Employee/Admin active được tiếp nhận và hoàn tất kiểm tra ở `da_dat`/`cho_chuyen_phat`; chỉ Admin active được thao tác ở `dang_trung_chuyen`. Foundation chỉ ghi bằng chứng và audit, chưa đổi Order sang `da_huy`, chưa hoàn kho hay tạo inventory transaction.

## Hiệu ứng khi chuyển trạng thái

- Khi đơn hợp lệ được tạo: lưu snapshot order/items/người nhận/ưu đãi và discount phân bổ theo item; ghi trạng thái đầu `da_dat`; trừ kho và ghi `sale` đúng một lần. COD bắt đầu `chua_thanh_toan`. VNPay chỉ tạo order sau khi thanh toán được xác minh thành công và reservation được chuyển thành `sale`.
- Khi hủy COD: sau khi toàn bộ Return Inspection đã completed, mỗi Order Item tạo đúng một ledger `cancel_restore` liên kết Order Item và Return Inspection. Ledger đồng thời tăng `sellable_delta` và `damaged_delta` theo hai kết quả phân loại; tổng hai delta bằng quantity. Không tạo ledger `damaged` riêng và không đổi `sold_quantity`. Nếu đã `dang_trung_chuyen`, chỉ admin được chuyển `da_huy` sau khi hàng quay lại và hoàn tất kiểm tra.
- Khi Customer Cancellation Request ở `da_dat` được approve: request phải còn pending và Order/lịch sử phải còn `da_dat`. Mỗi Order Item tạo đúng một `cancel_restore` liên kết request, không liên kết Return Inspection; toàn bộ quantity tăng sellable, damaged/sold không đổi. Request terminal, Order history, audit, projection, ledger, Coupon/Refund effects cùng commit hoặc cùng rollback. Reject chỉ terminal request và audit, không đổi Order hay các projection.
- Approve VNPay giữ Payment Attempt/Order payment ở `da_thanh_toan`, giữ Coupon Usage `consumed` và tạo một full Refund `pending` reason `customer_cancellation`. Chỉ Refund Processing thành công mới chuyển payment `hoan_tien` và release Coupon. Approve COD giữ payment `chua_thanh_toan`, không tạo Refund và release Coupon ngay.
- Khi COD được hủy: `order_status` chuyển `da_huy`, còn `payment_status` giữ `chua_thanh_toan`; không dùng `that_bai` vì COD chưa phát sinh giao dịch thanh toán thất bại. Coupon Usage giữ `order_id` và `consumed_at` làm bằng chứng, đồng thời chuyển sang `released` với thời gian server.
- COD hủy toàn bộ trả lượt coupon. VNPay chỉ trả lượt coupon sau full refund `succeeded`; refund `failed` không trả lượt, kể cả mã `free_shipping`.
- Đơn VNPay đã thanh toán khi hủy tạo refund riêng `pending`; refund có thể `succeeded` hoặc `failed`. Chỉ full refund trong phiên bản đầu; không hoàn vượt tiền đã thanh toán hoặc ghi thành công hai lần. Khi còn `pending`/`failed`, `payment_status` vẫn `da_thanh_toan`; chỉ chuyển `hoan_tien` khi `succeeded`. Refund cũng có thể gắn payment attempt thành công mà chưa có order.
- Khi COD `da_giao`: tăng `sold_quantity` đúng quantity từng Order Item một lần và đổi thanh toán sang `da_thanh_toan`; không đổi sellable/damaged và không tạo inventory ledger mới. Ledger `sale` vật lý đã được ghi khi đặt COD. Hạng dùng tiền sản phẩm sau discount, không shipping; full refund tương lai loại order khỏi chi tiêu và cập nhật hạng tự động.
- Mỗi hiệu ứng quan trọng phải gắn với event key duy nhất toàn cục và được thực hiện trong transaction với locking thích hợp; callback hoặc request lặp không tạo hiệu ứng lặp, còn cùng key trên Order/action/payload khác bị từ chối.

## Nhánh thanh toán VNPay

Luồng thanh toán có trạng thái riêng: `chua_thanh_toan`, `da_thanh_toan`, `that_bai`, `hoan_tien`. Trước khi chuyển VNPay, tạo `payment_attempt` và `stock_reservation` giữ hàng 15 phút; chưa tạo order. Discount từng line được phân bổ và snapshot ngay lúc initiation. Callback xác minh raw query, chữ ký, merchant, reference, amount và transaction number; thành công trong hạn tạo đúng một order `da_dat`, chuyển giữ hàng thành sale, tiêu thụ mã và chỉ xóa Cart line còn khớp snapshot trong cùng transaction. Callback giống hệt trả duplicate; callback xung đột không ghi đè evidence.

Callback thành công **sau khi reservation hết hạn**: kiểm tra lại tồn khả dụng và Coupon capacity trong transaction. Nếu đủ cả hai, tạo một order `da_dat`, xuất kho đúng một lần và consume chính usage đã released. Nếu thiếu một trong hai, không tạo order; vẫn lưu payment thành công và tạo đúng một full refund `pending`. Attempt lịch sử nhiều line có item discount nhưng thiếu phân bổ cũng đi Refund `snapshot_incomplete`; snapshot corruption rollback và trả mã retryable `99`. Không tiêu thụ coupon hay xóa giỏ khi không có order. Callback lặp không tạo order, sale hoặc refund thứ hai.

Refund external dùng contract at-most-once: prepare evidence `submitted` và commit trước HTTP, sau đó mới gọi VNPay ngoài transaction. Không retry tự động sau khi đã submitted. Admin không được đánh dấu request đang chạy là `ambiguous`; thao tác này chỉ mở sau operational lease cấu hình server đã validate, mặc định 120 giây và dài hơn HTTP timeout. Response có chữ ký hợp lệ, khớp chính Request ID/fingerprint đã lưu và đến sau stale mark vẫn có thể chốt `ambiguous → succeeded/failed` mà không phát sinh HTTP call mới. Chỉ response khớp amount/reference và đồng thời `00/00` mới tự động chuyển Refund sang `succeeded`, Payment Attempt sang `hoan_tien` và release Coupon đúng một lần. Kết quả từ chối chắc chắn chuyển `failed`; transport error, response hỏng/sai chữ ký, `05`, `06`, `94`, `98`, `99` hoặc code chưa rõ chuyển gateway evidence sang `ambiguous` nhưng giữ Refund pending. Admin active đối soát ambiguous trên Merchant Portal bằng event key và ghi chú bắt buộc; `querydr` không phải final authority trong MVP.

## Màn hình vận hành

Quản lý đơn hàng hỗ trợ tra cứu, xem chi tiết và trong Phase 1 cho Admin/Employee thực hiện đúng hai bước tiến vận chuyển đã nêu; các thao tác giao/hủy chưa xuất hiện. Danh sách hỗ trợ tìm mã đơn, tài khoản đặt, người nhận, email, số điện thoại, địa chỉ; lọc trạng thái vận chuyển/thanh toán, phương thức và khoảng thời gian; phân trang, mới nhất trước.
