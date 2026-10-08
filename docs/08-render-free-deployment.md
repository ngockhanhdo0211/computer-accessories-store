# Render Free deployment readiness

Tài liệu này mô tả checkpoint chuẩn bị, chưa phải xác nhận hệ thống đã sẵn sàng chạy thương mại. Không tạo tài nguyên cloud, không chứa credential và không thay thế hai checkpoint Aiven/Cloudinary.

## 1. Kiến trúc mục tiêu

- Một Render Free Web Service chạy Docker image của repository.
- Apache từ official `php:8.2-apache-bookworm`, chỉ phục vụ thư mục Laravel `public` trên `0.0.0.0:$PORT`.
- Aiven Free MySQL là database ngoài container, chỉ được cấu hình sau khi compatibility gate riêng đạt.
- Cloudinary Free là storage ảnh Product ở checkpoint riêng. Render không lưu upload lâu dài.
- VNPay Sandbox dùng các route Return/IPN hiện có sau khi Render cấp hostname HTTPS.
- Google OAuth chỉ cấu hình sau khi code và URL production đã tồn tại.
- Không có database, Redis, worker, cron hay persistent disk chạy cùng Web Service.

Dockerfile gồm ba stage:

1. `frontend`: Node 22, `npm ci`, Vite production build.
2. `composer_deps`: Composer 2, dependency production không có dev package và không chạy database/migration.
3. `runtime`: PHP 8.2 Apache, các extension cần thiết (`pdo_mysql`, `mbstring`, `intl`, `bcmath`, `curl`, `opcache`) và artifact từ hai stage trước.

Apache chạy parent process theo cơ chế chuẩn của official image và hạ worker xuống `www-data`. Chỉ `storage` và `bootstrap/cache` được cấp quyền ghi cho `www-data`; không dùng quyền `777`.

## 2. Giới hạn Render Free

Theo [Render Free documentation](https://render.com/docs/free), Free Web Service:

- sleep sau 15 phút không có inbound HTTP/WebSocket traffic;
- cold start thường mất khoảng một phút;
- mất mọi thay đổi filesystem khi sleep, restart hoặc redeploy;
- không hỗ trợ persistent disk, shell access hoặc one-off job;
- dùng chung hạn mức 750 free instance hours cho mỗi workspace trong một tháng;
- có thể bị restart và không phù hợp production thương mại.

Render mặc định cấp `PORT=10000`; container vẫn lấy giá trị động từ biến `PORT` và bind `0.0.0.0`. Xem [Web Services](https://render.com/docs/web-services) và [Health Checks](https://render.com/docs/health-checks).

## 3. Checklist trước deploy

- [ ] Support Chat commit sạch.
- [ ] Docker readiness checkpoint đã review và commit.
- [ ] Diff và toàn bộ quality gate đã được kiểm tra trước khi push branch dùng để deploy.
- [x] Aiven MySQL 8.4 compatibility gate PASS trên database QA tách biệt (Checkpoint 2).
- [ ] Cloudinary storage checkpoint PASS.
- [ ] Full PHPUnit suite và Vite build PASS.
- [ ] Có backup nguồn dữ liệu được phép đưa lên demo.
- [ ] Không có secret, `.env`, SQL dump hay upload development trong Git/image.
- [ ] Toàn bộ production environment variables đã chuẩn bị.
- [ ] `RUN_MIGRATIONS` vẫn là `false` cho tới khi compatibility gate và migration plan được duyệt.

## 4. Render environment variables

Chỉ nhập giá trị thật trong Render Dashboard. Không ghi chúng vào Git, Blueprint hoặc báo cáo.

| Biến | Mục đích |
| --- | --- |
| `APP_NAME` | Tên ứng dụng hiển thị. |
| `APP_ENV` | Bắt buộc `production`. |
| `APP_KEY` | Laravel application key hợp lệ; secret. |
| `APP_DEBUG` | Bắt buộc `false`. |
| `APP_URL` | URL HTTPS do Render cấp, không có localhost. |
| `LOG_CHANNEL` | Bắt buộc `stderr`; nên dùng `LOG_LEVEL=warning`. |
| `DB_CONNECTION` | Bắt buộc `mysql`. |
| `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | Kết nối Aiven, nhập sau compatibility gate. |
| `DB_CONNECT_TIMEOUT` | Timeout kết nối của readiness, từ 1 đến 2 giây; Blueprint đặt `2`. |
| `MYSQL_ATTR_SSL_CA` | Bắt buộc là đường dẫn runtime tới CA được upload bằng Render Secret File, ví dụ `/etc/secrets/aiven-ca.pem`. |
| `MYSQL_ATTR_SSL_VERIFY_SERVER_CERT` | Bắt buộc `true`; PDO phải xác minh chứng thư máy chủ bằng CA đã cấu hình. |
| `SESSION_DRIVER` | `database`; migration `sessions` đã tồn tại. |
| `SESSION_SECURE_COOKIE` | `true`. |
| `SESSION_HTTP_ONLY` | `true`. |
| `SESSION_SAME_SITE` | `lax` hoặc `strict`; hiện chọn `lax`. |
| `CACHE_STORE` | `database`; migrations `cache` và `cache_locks` đã tồn tại. |
| `QUEUE_CONNECTION` | `sync`; checkpoint này không có worker. |
| `FILESYSTEM_DISK` | `public` chỉ còn phục vụ local legacy/static workflow; Product upload production không phụ thuộc filesystem Render. |
| `PRODUCT_IMAGE_DRIVER` | Bắt buộc `cloudinary` trong production. Local/test mặc định `local`. |
| `CLOUDINARY_CLOUD_NAME`, `CLOUDINARY_API_KEY`, `CLOUDINARY_API_SECRET` | Credential Cloudinary, nhập bằng secret variables trong Render Dashboard; không ghi vào Blueprint/Git. |
| `CLOUDINARY_FOLDER` | Bắt buộc đúng `computer-accessories-store/production`; QA dùng folder riêng ngoài production. |
| `RUN_MIGRATIONS` | Mặc định `false`; chỉ `true` theo migration plan đã duyệt. |
| `VNPAY_PAYMENT_URL`, `VNPAY_TERMINAL_CODE`, `VNPAY_HASH_SECRET`, `VNPAY_RETURN_URL` | VNPay Sandbox hiện có. Return URL phải là URL HTTPS Render cộng `/checkout/vnpay/return`. |
| `VNPAY_REFUND_URL`, `VNPAY_REFUND_CREATE_BY`, `VNPAY_REFUND_IP_ADDRESS` | Bắt buộc khi VNPay được bật. URL phải đúng Refund Sandbox allowlist; `CREATE_BY` là định danh vận hành không chứa khoảng trắng; IP là địa chỉ public hợp lệ mà VNPay chấp nhận. Thiếu hoặc sai cấu hình sẽ chặn nút gửi và không tạo gateway attempt. |
| `VNPAY_REFUND_CONNECT_TIMEOUT`, `VNPAY_REFUND_TIMEOUT`, `VNPAY_REFUND_SUBMISSION_STALE_SECONDS` | Mặc định lần lượt `5`, `15`, `120` giây. Connect timeout không vượt total timeout; stale lease phải từ 60–3600 giây và lớn hơn total timeout để không cho phép đối soát khi request còn chạy. |

Không lưu nội dung CA trong Git, Docker image, Blueprint hay một environment variable. Trong Render Dashboard, tạo Secret File tên `aiven-ca.pem`; Render mount file tại `/etc/secrets/aiven-ca.pem`, sau đó đặt `MYSQL_ATTR_SSL_CA` thành đúng đường dẫn đó. `app:validate-production` dừng startup nếu CA không đọc được hoặc xác minh chứng thư máy chủ không được bật. Không dựa vào cấu hình `require_secure_transport` phía server để thay thế xác minh TLS phía client.

`DB_SSL_MODE` không được khai báo vì kết nối PDO hiện dùng trực tiếp `MYSQL_ATTR_SSL_CA` và `PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT`.

## 4.1. Kết quả Aiven MySQL 8.4 Compatibility

Checkpoint 2 đã được kiểm tra trên một database QA rỗng, tách biệt với development và production:

- MySQL 8.4.8, InnoDB, `utf8mb4`, TLS 1.3 với xác minh CA và chứng thư máy chủ;
- toàn bộ 30 migration chạy thành công; rollback/up có mục tiêu của migration cuối chạy thành công mà không dùng `migrate:fresh`;
- schema sau migration gồm 36 bảng, 59 foreign key, 40 unique constraint, 80 CHECK constraint, 47 trigger, 55 cột thời gian precision 6 và 6 cột JSON native;
- `CHECK TABLE` đạt cho toàn bộ bảng hỗ trợ; các negative test trực tiếp xác nhận FK/unique/CHECK/trigger, JSON, microsecond datetime, transaction rollback, row locking và idempotency;
- mọi fixture QA đã được dọn, chỉ giữ schema và hai Shipping Rate mặc định do migration tạo.

Khác biệt dialect duy nhất cần sửa là biểu thức chính quy phân biệt hoa/thường trong CHECK: MariaDB 10.4 giữ `REGEXP BINARY`, còn MySQL 8.4 dùng `REGEXP_LIKE(..., ..., 'c')`. Hai nhánh giữ cùng pattern và không làm yếu constraint. SQLite tiếp tục dùng nhánh trigger/CHECK hiện có.

Checkpoint 3 đã bổ sung Cloudinary variables. `app:validate-production` fail-closed nếu driver không phải Cloudinary, credential thiếu/placeholder hoặc folder không đúng production; output chỉ nêu tên biến lỗi. Google variables vẫn thuộc checkpoint sau.

## 5. Startup contract

`docker/render-start.sh` thực hiện theo thứ tự fail-fast:

1. Kiểm tra `PORT` và `RUN_MIGRATIONS` (`true` hoặc `false`).
2. Chạy `php artisan app:validate-production`; lỗi chỉ nêu tên biến, không in giá trị.
3. Tạo các runtime directory với owner `www-data`.
4. Chỉ tạo `public/storage` khi path chưa tồn tại; symlink có sẵn phải trỏ chính xác tới `../storage/app/public`, còn file/directory/symlink lạ làm startup dừng.
5. Cảnh báo rõ local/public storage là ephemeral.
6. Nếu và chỉ nếu `RUN_MIGRATIONS=true`, chạy `php artisan migrate --force`—không fresh, seed hoặc import.
7. Tạo config, route và view cache bằng `www-data` sau migration để không dùng stale config.
8. Kiểm tra Apache config và `exec apache2-foreground` để nhận signal trực tiếp.

Không bật migration tự động trong checkpoint này. Khi nhiều container cùng start, migration đồng thời có thể tranh chấp schema; Render Free mục tiêu chỉ có một instance nhưng deploy cũ/mới vẫn có thể overlap. Chỉ bật sau Aiven gate, backup và kế hoạch deploy cụ thể. Migration thất bại làm container không start.

## 6. Health, HTTPS và proxy

- `GET|HEAD /up`: liveness public, response text tối thiểu, không middleware web/session và không query database. Render dùng path này để tránh restart loop khi database chập chờn.
- `GET|HEAD /health/ready`: chạy đúng một `SELECT 1`; trả `200 ready` hoặc `503 unavailable`, không lộ exception/host/credential.
- Render terminate TLS. Laravel tin proxy IP động nhưng chỉ đọc `X-Forwarded-For` và `X-Forwarded-Proto`; không tin `X-Forwarded-Host` hoặc forwarded port. Host trực tiếp phải khớp chính xác hostname trong `APP_URL`; Apache từ chối Host có port, userinfo hoặc nhiều giá trị.
- `APP_URL` là HTTPS origin không có path, query, fragment hay explicit port. `VNPAY_RETURN_URL` phải dùng cùng hostname và đúng path `/checkout/vnpay/return`.
- Readiness tạo kết nối riêng với timeout tối đa 2 giây rồi chạy đúng một `SELECT 1`; endpoint không thay thế compatibility gate cho TLS/Aiven.
- Request không có forwarded headers ở local vẫn dùng HTTP, tránh force-HTTPS/redirect loop.
- Cookie production phải secure, httpOnly, SameSite `lax`, không đặt domain localhost.
- `APP_URL` và `VNPAY_RETURN_URL` phải đổi sang hostname Render HTTPS sau khi service được cấp URL.

## 7. Session, cache, queue và logging

- Session và cache dùng database để không phụ thuộc filesystem ephemeral. Các bảng tương ứng đã có nhưng phải qua Aiven compatibility gate.
- Queue dùng `sync`; không khai báo worker giả hoặc Redis.
- Log production đi `stderr`; `APP_DEBUG=false` để HTTP exception không lộ stack, SQL hoặc config.
- Không log password, application key, DB/VNPay secrets, session/CSRF token hay nội dung Support Chat.

## 8. Product image storage contract

Production dùng Cloudinary; browser gửi file tới Laravel và Laravel kiểm tra MIME/nội dung, format và giới hạn 5 MB trước khi signed upload bằng SDK chính thức. Public ID do server sinh dưới `computer-accessories-store/production/products/{product_id}/`; tối đa 8 ảnh/Product. Database chỉ lưu metadata cần render và đối soát, không lưu credential hay signature.

Local/test tiếp tục dùng public disk và row local cũ vẫn render bình thường. Không tự migrate hai ảnh development lên cloud. Render không cần persistent disk cho Product upload; static assets trong repository vẫn hoạt động. Khi upload nhiều ảnh hoặc ghi DB thất bại, các asset đã tạo được cleanup. Xóa chỉ tác động asset thuộc đúng Product folder; kết quả `not found` được coi là idempotent, còn lỗi chưa xác nhận phải được log đã khử bí mật và đưa vào reconciliation thay vì giả thành công.

Rollback migration metadata chỉ được phép khi không còn row/metadata Cloudinary. Trước rollback production phải chuyển dữ liệu có chủ đích và xác minh asset lifecycle; không dùng rollback để xóa metadata đang tham chiếu asset thật.

Cloudinary Free có quota storage, bandwidth và transformation theo tài khoản; theo dõi usage trong Dashboard và không coi Free tier là cam kết SLA/backup. Sau deploy, smoke test bằng một Product thử nghiệm được phép: upload ảnh, xác minh URL HTTPS tải đúng MIME, đổi primary/reorder rồi xóa ảnh/Product; cuối cùng kiểm tra asset không còn trong đúng folder Product. Không duyệt hoặc xóa asset ngoài prefix vừa tạo. Khi rollback application, giữ nguyên Cloudinary asset và schema cho tới khi phiên bản cũ đã được xác nhận hiểu metadata; rollback code không đồng nghĩa xóa asset.

## 9. Scheduler và reservation cleanup

Không chạy scheduler vô hạn trong web process và không tạo paid Render Cron/public maintenance endpoint. Tồn khả dụng vẫn dựa trực tiếp trên `expires_at`, vì vậy reservation hết hạn không giữ tồn ngay cả khi cleanup chưa chạy.

Command `php artisan stock-reservations:release-expired` là idempotent và chỉ dọn record hết hạn. Trước demo có thể chạy thủ công từ máy local được phép kết nối production DB. External free scheduler là quyết định sau, không blocker cho deploy cơ bản.

## 10. Quy trình deploy dự kiến

1. Tạo Aiven Free MySQL.
2. Chạy compatibility gate trên database QA tách biệt.
3. Tạo và tích hợp Cloudinary Free.
4. Tạo Render Free Web Service từ `render.yaml`.
5. Chờ Render cấp URL.
6. Cập nhật `APP_URL`, `VNPAY_RETURN_URL` và callback/IPN Sandbox bằng URL HTTPS thật.
7. Backup, duyệt migration plan, sau đó mới cho phép `php artisan migrate --force` một lần an toàn.
8. Import bộ dữ liệu demo sạch bằng quy trình riêng; không copy mù database development.
9. Smoke test liveness, readiness, asset, auth và nghiệp vụ.
10. Sau cùng mới cấu hình Google OAuth/VNPay callback chính thức cho demo.

Blueprint chỉ tạo một Free Docker Web Service. Không tạo Render Postgres, disk, cron, worker hay paid resource. Các biến secret dùng `sync: false`.
Automatic deploy bị tắt trong Blueprint. Chỉ deploy thủ công sau khi đã review diff và chạy lại quality gate của commit mục tiêu.

## 11. Demo rehearsal

- Mở URL trước buổi demo 2–3 phút để vượt cold start.
- Kiểm tra `/up`, `/health/ready`, ảnh static, login và ba role Customer/Admin/Employee.
- Kiểm tra catalog, cart, checkout, COD, Support Chat, VNPay Sandbox và refund theo dữ liệu demo.
- Không demo upload/delete ảnh trước Cloudinary.
- Chuẩn bị mạng dự phòng và không phụ thuộc cold start dưới một phút.

### Bootstrap Admin production khi Render Free không có Shell

Chỉ chạy từ máy vận hành được phép kết nối Aiven sau khi đã checkout đúng commit deploy và cài dependency production. Nạp các biến production bằng cơ chế private ngoài repository, giữ `APP_ENV=production`, `DB_CONNECTION=mysql`, CA cục bộ và xác minh chứng thư máy chủ; không copy secret vào `.env`, command line, history hoặc Git. Sau khi xác minh database đích chính xác là production đã duyệt, chạy tương tác:

```text
php artisan app:promote-customer-to-admin admin-operator@example.com
```

Email phải thuộc một Customer hiện hữu đang `active`. Đọc kỹ cảnh báo `APP_ENV=production` và chỉ trả lời `yes` sau khi đối chiếu đúng tài khoản/database. Lệnh production chạy với `--no-interaction` sẽ fail-closed và không truy vấn/thay đổi tài khoản; không có option bypass confirmation. Lệnh không tạo hay đổi password, không sửa membership/session và không có HTTP endpoint; thay đổi role cùng audit hệ thống được commit nguyên tử. Chạy lại chỉ trả thành công khi audit evidence của lần bootstrap trước còn nguyên vẹn. Không đặt lệnh này trong Render startup hoặc deploy hook.

## 12. Rollback

- Rollback Render về image/commit trước trong số deployment còn được Render giữ.
- Không rollback migration đã có business evidence.
- Không dùng `migrate:fresh`, không seed Admin và không import SQL trong startup.
- Không restore mù database development lên production.
- Giữ checkpoint Docker readiness thành commit độc lập, nhỏ và dễ revert.

Render cung cấp URL HTTPS công khai qua giao thức HTTP. Điều này đáp ứng yêu cầu truy cập website công khai bằng HTTP protocol; TLS chỉ bảo vệ HTTP trên đường truyền và không thay đổi kiến trúc ứng dụng web.
