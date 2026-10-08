# 03 — Ma trận vai trò và quyền

Ký hiệu: **Có** = được phép trong phạm vi và điều kiện nêu dưới bảng; **Không** = không được phép. Mọi quyền phải được xác thực tại backend. Khách chỉ thao tác dữ liệu của mình; admin không được vượt bất biến nghiệp vụ.

| Hành động | Customer | Employee | Admin |
| --- | :---: | :---: | :---: |
| Tự đăng ký tài khoản customer | Có | Không | Không |
| Đăng nhập, đăng xuất, sửa hồ sơ của mình | Có | Có | Có |
| Xem, tìm, lọc sản phẩm công khai | Có | Có | Có |
| Quản lý giỏ và áp mã của mình | Có | Không | Không |
| Đặt COD/VNPay, xem đơn của mình | Có | Không | Không |
| Gửi yêu cầu hủy Order của mình ở `da_dat` | Có | Không | Không |
| Xem và approve/reject Customer Cancellation Request pending | Không | Có | Có |
| Xem/gửi hội thoại hỗ trợ của chính mình | Có | Không | Không |
| Xem shared support inbox, trả lời và đóng/mở lại hội thoại | Không | Có | Có |
| Hủy đơn `cho_chuyen_phat` có lý do | Không | Có | Có |
| Chuyển `dang_trung_chuyen` sang `da_huy` sau xác nhận hàng quay lại | Không | Không | Có |
| Tạo review cho order item `da_giao` của mình (tối đa một/item, rating 1–5) | Có | Không | Không |
| Sửa review của mình trong 7 ngày; soft delete review của mình | Có | Không | Không |
| Tra cứu và xem chi tiết đơn toàn hệ thống | Không | Có | Có |
| Cập nhật vận chuyển theo luồng hợp lệ | Không | Có | Có |
| Tiếp nhận/hoàn tất kiểm tra hàng hoàn của Order `da_dat`/`cho_chuyen_phat` | Không | Có | Có |
| Tiếp nhận/hoàn tất kiểm tra hàng hoàn của Order `dang_trung_chuyen` | Không | Không | Có |
| Tạo đề nghị điều chỉnh kho có lý do | Không | Có | Có |
| Duyệt đề nghị/điều chỉnh kho trực tiếp có lý do và audit | Không | Không | Có |
| Quản lý sản phẩm, ảnh, danh mục, thương hiệu | Không | Không | Có |
| Quản lý khách hàng và nhân viên | Không | Không | Có |
| Approve, hide, soft delete review | Không | Không | Có |
| Quản lý mã giảm giá; yêu cầu tính lại hạng có audit | Không | Không | Có |
| Gửi một lần Refund pending tới VNPay | Không | Không | Có, chỉ tài khoản active |
| Đối soát thủ công Refund ambiguous | Không | Không | Có, bắt buộc ghi chú/chứng từ nếu có |
| Sửa hạng thành viên trực tiếp | Không | Không | Không |
| Xem thống kê và audit log | Không | Không | Có |
| Sửa tổng tiền/giá snapshot của đơn | Không | Không | Không |
| Chuyển trạng thái trái luồng, xóa đơn | Không | Không | Không |
| Khóa hoặc hạ quyền admin cuối cùng | Không | Không | Không |

## Điều kiện áp dụng

- `locked` là khóa tạm, `inactive` là ngừng sử dụng; cả hai từ chối đăng nhập và thu hồi session. Admin được mở `locked` hoặc kích hoạt lại `inactive`; password reset không tự mở khóa, `inactive` không tự khôi phục.
- Chỉ customer tự đăng ký; không có endpoint đăng ký Employee/Admin công khai. Admin production đầu tiên dùng `app:promote-customer-to-admin {email}`; Employee production dùng `app:promote-customer-to-employee {email}`. Cả hai lệnh nội bộ chỉ nâng Customer hiện hữu đang `active`, khóa hàng và ghi audit hệ thống nguyên tử; không tạo hoặc đổi mật khẩu. Factory chỉ dùng cho test.
- Customer chỉ xem, sửa hồ sơ/giỏ/đơn/review thuộc tài khoản mình; không được truyền ID người khác để vượt quyền. Review gắn order item của order `da_giao`, tối đa một review/item, rating 1–5; tạo trong 90 ngày, sửa trong 7 ngày. Review đã duyệt sửa xong về `pending`.
- Customer không tự hủy trực tiếp: chỉ gửi một request cho Order của mình còn `da_dat`. Employee/Admin active approve hoặc reject request pending; reject bắt buộc ghi chú. Luồng này không dùng Return Inspection. Staff cancellation độc lập vẫn yêu cầu lý do và bằng chứng Return Inspection theo lifecycle; `dang_trung_chuyen` chỉ Admin chuyển `da_huy` sau khi hàng quay lại và được kiểm tra. Xem [04-order-lifecycle.md](04-order-lifecycle.md).
- Nhân viên không quản lý sản phẩm, danh mục, thương hiệu, nhân viên hay phân quyền; không sửa tổng tiền/giá chi tiết đơn và không bỏ qua thứ tự vận chuyển.
- Admin quản lý hệ thống nhưng vẫn bị chặn bởi kiểm tra trạng thái, tồn kho, thanh toán, quyền sở hữu khi hành động thay mặt khách và bảo vệ admin cuối cùng. Thao tác nhạy cảm phải có audit log.
- Refund external và reconciliation chỉ do Admin active thực hiện. Employee/Customer không được kích hoạt; không có batch hoặc command tự động gửi Refund. Kết quả ambiguous phải được kiểm tra trên VNPay Merchant Portal trước khi Admin xác nhận succeeded/failed.
