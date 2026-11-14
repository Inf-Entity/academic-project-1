# Hệ thống Quản lý Học phí Sinh viên

Ứng dụng quản lý học phí sinh viên được xây dựng trên **PHP 8.1+**, **MySQL/MariaDB** và giao diện **HTML, CSS, JavaScript thuần**. Dự án hướng đến mô hình triển khai cục bộ bằng XAMPP, không yêu cầu npm, framework frontend, CDN bên ngoài hoặc bước build.

Các thao tác xác thực, phân quyền, truy cập dữ liệu và xử lý nghiệp vụ tài chính được thực hiện ở phía máy chủ. Frontend giao tiếp với backend thông qua API.

## Mục lục

- [Tổng quan](#tổng-quan)
- [Công nghệ sử dụng](#công-nghệ-sử-dụng)
- [Cấu trúc dự án](#cấu-trúc-dự-án)
- [Yêu cầu hệ thống](#yêu-cầu-hệ-thống)
- [Cài đặt và chạy ứng dụng](#cài-đặt-và-chạy-ứng-dụng-setup-guide)
- [Tài khoản dùng thử](#tài-khoản-dùng-thử)
- [Chạy kiểm thử tự động](#chạy-kiểm-thử-tự-động)
- [Checklist kiểm thử thủ công](#checklist-kiểm-thử-thủ-công)
- [Giới hạn đã biết](#giới-hạn-đã-biết)
- [Trạng thái yêu cầu](#trạng-thái-yêu-cầu)

## Tổng quan

Hệ thống hỗ trợ quản lý hồ sơ học phí và các nghiệp vụ tài chính liên quan, bao gồm:

- Quản lý người dùng, đăng nhập và phiên làm việc.
- Quản lý sinh viên, học kỳ, mức thu và hồ sơ học phí.
- Ghi nhận thanh toán, theo dõi công nợ và lịch sử giao dịch.
- Xử lý miễn giảm, hoàn tiền và đảo mục giao dịch.
- Lập và in biên lai.
- Theo dõi nhật ký kiểm toán và đối soát số liệu tài chính.
- **Phân hệ Cổng Sinh viên (Student Portal):** Cho phép sinh viên đăng nhập vào tài khoản cá nhân và chỉ sử dụng đúng 4 chức năng nghiệp vụ (Đăng ký tín chỉ, Tra cứu công nợ học phí, Thanh toán học phí trực tuyến qua cổng mô phỏng, và Lịch sử thanh toán). Hệ thống cách ly dữ liệu tuyệt đối giữa các sinh viên và giữa sinh viên với các chức năng quản trị.

Các tính năng và nghiệp vụ nêu trên được mô tả theo cấu trúc dự án và bộ kiểm thử đi kèm.

## Công nghệ sử dụng

| Thành phần | Công nghệ |
| --- | --- |
| Backend | PHP 8.1+ |
| Cơ sở dữ liệu | MySQL / MariaDB |
| Kết nối cơ sở dữ liệu | PDO |
| Frontend | HTML5, CSS3, JavaScript ES6+ thuần |
| Môi trường chạy cục bộ | XAMPP (Apache và MySQL/MariaDB) |
| Kiểm thử backend | PHP CLI |
| Kiểm thử frontend | JavaScript (`?test=1`) |

Frontend không sử dụng npm, framework hoặc CDN bên ngoài.

## Cấu trúc dự án

```text
my-web-project/
│
├── index.php                        # Điểm khởi chạy ứng dụng
│
├── frontend/                        # Giao diện HTML/CSS/JavaScript
│   ├── index.html                   # Giao diện SPA quản lý học phí
│   ├── css/
│   │   └── style.css                # Giao diện responsive và CSS in biên lai
│   ├── js/
│   │   ├── main.js                  # Sự kiện giao diện, modal, tabs và in ấn
│   │   ├── api.js                   # API client, CSRF Token và Idempotency UUID
│   │   └── calc.js                  # Tính toán phía client và chuyển số thành chữ
│   └── assets/
│       ├── images/                  # Ảnh giao diện
│       └── icons/                   # Biểu tượng SVG
│
├── backend/                         # Các thành phần backend
│   ├── config/
│   │   └── database.php             # Kết nối PDO, múi giờ và quản lý kết nối
│   ├── controllers/
│   │   └── UserController.php       # Đăng nhập, phiên, CSRF và người dùng
│   ├── models/
│   │   └── User.php                 # Truy vấn người dùng và xử lý mật khẩu
│   └── api/
│       └── users.php                # API người dùng và phiên làm việc
│
├── pages/                           # Các trang thông tin
│   ├── about.php                    # Giới thiệu hệ thống
│   └── contact.php                  # Liên hệ và gửi phản hồi
│
├── database/                        # SQL và dữ liệu khởi tạo
│   ├── database.sql                 # CSDL hợp nhất gồm DDL và seed data
│   ├── schema.sql                   # Cấu trúc bảng, khóa ngoại và view
│   ├── seed.sql                     # Dữ liệu mẫu
│   ├── create_app_user.sql          # Tạo tài khoản DB quyền tối thiểu
│   └── seed_users.php               # Khởi tạo mật khẩu Bcrypt qua CLI
│
├── api/                             # API modules và handlers
│   ├── index.php                    # Front controller cho /api/...
│   ├── handlers/                    # Xử lý auth, students, fees, payments, refunds...
│   └── lib/                         # Thư viện db, auth, csrf, validate, calc, audit...
│
├── config/                          # Cấu hình ứng dụng
│   ├── config.php                   # Cấu hình môi trường (không đưa lên Git)
│   └── config.example.php           # Tệp cấu hình mẫu
│
├── tests/                           # Bộ kiểm thử
│   ├── run.php                      # Test runner backend
│   └── tests.js                     # Test runner frontend (?test=1)
│
├── .htaccess                        # Quy tắc bảo mật Apache
├── .gitignore                       # Danh sách tệp loại trừ khỏi Git
├── LICENSE                          # Giấy phép MIT
└── README.md                        # Tài liệu dự án
```

## Yêu cầu hệ thống

- PHP **8.1** trở lên.
- MySQL hoặc MariaDB.
- Apache và PHP được cấu hình trong XAMPP.
- PHP CLI có thể truy cập từ Terminal/Command Prompt/PowerShell để chạy script khởi tạo và kiểm thử.
- Trình duyệt web hiện đại.

## Cài đặt và Chạy Ứng dụng (Setup Guide)

Hệ thống được thiết kế để cài đặt và chạy ngay chỉ trong **3 bước đơn giản** trên XAMPP.

### Bước 1: Đưa dự án vào thư mục web
Chép toàn bộ thư mục dự án vào thư mục `htdocs` của XAMPP:

```text
C:\xampp\htdocs\tuition-system
```

*(Hoặc tạo liên kết Junction trỏ từ `C:\xampp\htdocs\tuition-system` tới thư mục mã nguồn).*

### Bước 2: Khởi động XAMPP
Mở **XAMPP Control Panel**, nhấn nút **Start** cho cả 2 dịch vụ:
- ✅ **Apache**
- ✅ **MySQL** (MariaDB)

### Bước 3: Nạp cơ sở dữ liệu (Chọn 1 trong 2 cách)
Tệp `database/database.sql` là tệp hợp nhất mới nhất, đã bao gồm toàn bộ cấu trúc bảng (schema), dữ liệu mẫu (seed data) và tự động tạo tài khoản kết nối. Bạn chỉ cần nạp duy nhất tệp này:

- **Cách 1: Sử dụng phpMyAdmin (Nhập chuẩn theo thứ tự từng phần)**:
  1. Mở trình duyệt web và vào địa chỉ: [http://localhost/phpmyadmin/](http://localhost/phpmyadmin/)
  2. Tạo cơ sở dữ liệu `tuition_system` với bảng mã `utf8mb4_unicode_ci` (nếu chưa có).
  3. Chọn cơ sở dữ liệu `tuition_system` ở danh sách bên trái.
  4. Bấm tab **Nhập** (Import). Xác nhận mục **Bảng mã của tập tin** (Character set of the file) là **utf-8** trước khi nạp tệp. Thực hiện nhập lần lượt các tệp theo đúng thứ tự:
     - **Bước 1:** Nhập `database/schema.sql` (Khởi tạo cấu trúc bảng, khóa ngoại RESTRICT, chỉ mục).
     - **Bước 2:** Nhập `database/create_app_user.sql` (Tạo người dùng `tuition_user` với quyền tối thiểu).
     - **Bước 3:** Nhập `database/seed.sql` (Nạp dữ liệu mẫu chuẩn cho 9 bảng nghiệp vụ, gồm: 13 sinh viên, 13 hồ sơ học phí, 8 miễn giảm, 13 giao dịch sổ cái, 4 yêu cầu hoàn tiền).
  5. Mở Terminal / PowerShell và chạy lệnh CLI để khởi tạo tài khoản quản trị viên:
     ```bash
     cd C:\xampp\htdocs\tuition-system
     C:\xampp\php\php.exe php database/seed_users.php
     ```
  *(Lưu ý: Bạn có thể re-import `schema.sql` và `seed.sql` nhiều lần liên tiếp mà không gặp lỗi).*

- **Cách 2: Sử dụng tệp hợp nhất `database/database.sql` hoặc dòng lệnh nhanh**:
  - **phpMyAdmin**: Chọn database `tuition_system`, chọn tab **Nhập** (Import), đảm bảo **Bảng mã của tập tin** (Character set of the file) là **utf-8**, Import tệp hợp nhất `database/database.sql`, sau đó chạy `php database/seed_users.php`.
  - **Dòng lệnh Terminal / PowerShell**:
    ```bash
    mysql -u root -e "CREATE DATABASE IF NOT EXISTS tuition_system CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
    mysql -u root tuition_system -e "source C:/xampp/htdocs/tuition-system/database/database.sql"
    php database/seed_users.php
    ```

---

### Bước 4: Mở ứng dụng và Đăng nhập
Mở trình duyệt web và truy cập:

👉 **[http://localhost/tuition-system/](http://localhost/tuition-system/)**

Đăng nhập bằng tài khoản Quản trị viên:
- **Tên đăng nhập:** `admin`
- **Mật khẩu:** `Admin@123`

---

### Tùy chỉnh Nâng cao (Tùy chọn)
- **Tệp cấu hình**: Dự án đã được thiết lập sẵn cấu hình chuẩn trong `config/config.php` (User: `tuition_user`, Mật khẩu: `TuitionApp2026!Sec`, Database: `tuition_system`).
- Nếu bạn muốn chỉnh sửa cổng kết nối, tên database hoặc phân quyền riêng, có thể chỉnh sửa trực tiếp trong `config/config.php` (mẫu tham khảo tại `config/config.example.php`).
- **Môi trường sản xuất (Production)**: Luôn đổi mật khẩu người dùng mặc định và đảm bảo tệp `config/config.php` được bảo vệ, không commit mật khẩu nhạy cảm lên Git.

## Tài khoản dùng thử

Các tài khoản sau phục vụ kiểm thử và đánh giá chức năng trên môi trường cục bộ.

> [!WARNING]
> Đây là thông tin đăng nhập mẫu. Không sử dụng các mật khẩu này trong môi trường production. Hãy thay đổi hoặc vô hiệu hóa tài khoản thử nghiệm trước khi triển khai thực tế.

| Tên đăng nhập | Mật khẩu | Vai trò | Mục đích |
| --- | --- | --- | --- |
| `admin` | `Admin@123` | `admin` | Kiểm thử các nghiệp vụ quản trị toàn quyền |
| `staff01` | `Staff@123` | `admin` | Kiểm thử người thao tác trong Audit Log và biên lai |
| `sv001` | `Student@001` | `student` | Sinh viên SV001 (Nguyễn Văn An): đã nộp đủ HK1 2026-2027, có nợ một phần và nộp thừa HK2 2025-2026 chặn thanh toán |
| `sv002` | `Student@002` | `student` | Sinh viên SV002 (Trần Thị Bình): thanh toán một phần HK1 2026-2027 còn nợ 10.300.000 ₫, dùng cho kiểm thử nộp tiền |
| `sv003` | `Student@003` | `student` | Sinh viên SV003 (Lê Hoàng Cường): chưa thanh toán HK1 2026-2027 còn nợ 8.200.000 ₫ |
| `sv011` | `Student@011` | `student` | Sinh viên SV011 (Ngô Quang Khải): chưa có hồ sơ học phí, dùng cho kiểm thử đăng ký tín chỉ học kỳ mở |
| `sv006` | `Student@006` | `student` | Sinh viên SV006 (Võ Minh Đức): tài khoản có cờ `must_change_password = 1`, dùng cho kiểm thử bắt buộc đổi mật khẩu |

## Chạy kiểm thử tự động

### Backend

Chạy tại thư mục gốc của dự án:

```bash
php tests/run.php
```

Bộ kiểm thử sử dụng cơ sở dữ liệu riêng `tuition_test`, tự động reset bảng và nạp dữ liệu mẫu trước khi kiểm tra (xác minh số lượng chuẩn: 13 sinh viên, 13 hồ sơ học phí, 8 miễn giảm, 13 giao dịch sổ cái, 4 yêu cầu hoàn tiền). Toàn bộ **226/226 bài kiểm thử** tích hợp backend thuộc 22 nhóm quy tắc nghiệp vụ và bảo mật đã vượt qua thành công (100% PASS):

Các nội dung được kiểm tra gồm:

- Tính học phí gốc, miễn giảm theo phần trăm hoặc số tiền cố định và quy tắc làm tròn.
- Từ chối khoản thanh toán vượt số tiền còn nợ và bảo toàn sổ cái khi giao dịch bị từ chối.
- Các trường hợp biên: thanh toán thiếu 1 đồng, thanh toán đủ, số phải thu bằng 0 và thanh toán vượt mức.
- Lưu snapshot đơn giá tín chỉ để không thay đổi khi đơn giá học kỳ được chỉnh sửa.
- Quy trình hoàn tiền; giới hạn số tiền hoàn theo khoản nộp thừa khả dụng; maker-checker kiểm soát quyền duyệt.
- Bút toán đảo mục, ngăn đảo mục lặp lại và ngăn đảo mục của giao dịch đảo mục.
- Idempotency bằng `request_id` theo định dạng UUID trên cả luồng Quản trị và Sinh viên.
- Đánh số biên lai nguyên tử theo ngày với định dạng `PT-YYYYMMDD-NNNN`; số thứ tự bắt đầu lại từ `0001` vào ngày mới.
- Kiểm thử đồng thời: hai tiến trình PHP cùng thanh toán một hồ sơ; chỉ một tiến trình được chấp nhận khi khoản còn nợ không đủ cho cả hai.
- Chuẩn hóa tìm kiếm tiếng Việt, bao gồm NFD/NFC và chữ hoa/chữ thường.
- Ràng buộc toàn vẹn khi xóa sinh viên đã phát sinh công nợ hoặc hồ sơ.
- Đối soát phương trình `Collected + Outstanding = Receivable`.
- Bảo mật phiên: HTTP 401 khi chưa xác thực, HTTP 403 khi CSRF Token thiếu hoặc không hợp lệ và khóa tài khoản 15 phút sau 5 lần đăng nhập sai.
- Kiểm tra dữ liệu nhập: chấp nhận định dạng `1.500.000` và `1,500,000`; từ chối số âm, số 0, số thập phân `1.5`, ký tự không hợp lệ và dữ liệu vượt giới hạn Unicode.
- Từ chối ngày thanh toán trong tương lai.
- **Phân hệ Sinh viên & Bảo mật cô lập (Parts 1-4):**
  - Cấp tài khoản sinh viên với mật khẩu tạm 12 ký tự ngẫu nhiên (`random_bytes`), một tài khoản duy nhất cho mỗi sinh viên (HTTP 409).
  - Bắt buộc đổi mật khẩu lần đầu (`must_change_password = 1`), chặn mọi endpoint khác với `PASSWORD_CHANGE_REQUIRED` (HTTP 403).
  - Ràng buộc mật khẩu mới (tối thiểu 8 ký tự, có chữ và số, xác nhận khớp, từ chối tái sử dụng mật khẩu tạm thời/hiện tại).
  - Đăng ký tín chỉ: kiểm tra giới hạn min/max theo `student_settings` (1 - 30), kiểm tra học kỳ mở và hạn đóng; tự động chụp snapshot đơn giá; chặn sửa tín chỉ khi đã có thanh toán; tự động tính lại Gross và Net.
  - Thanh toán học phí: xử lý qua dịch vụ cổng thanh toán mô phỏng `SimulatedGateway`, lưu `gateway_ref` nguyên tử, sinh số biên lai `PT-YYYYMMDD-NNNN`, chặn thanh toán khi hồ sơ nộp thừa (`OVERPAID_BLOCKED`), từ chối phương thức `Tiền mặt`.
  - Công nợ & Lịch sử: tổng hợp nợ đa học kỳ, hiển thị nhãn tiếng Việt chuẩn ("Thanh toán", "Đảo bút toán", "Nhận chuyển khoản", "Chuyển đi", "Hoàn tiền"), số dư lũy kế từng học kỳ, từ chối khoảng ngày `to < from` và khoảng ngày vượt quá 5 năm; yêu cầu hoàn tiền đang chờ duyệt hiển thị cảnh báo riêng.
  - Cô lập tuyệt đối: Sinh viên A gọi dữ liệu hoặc thanh toán vào hồ sơ/biên lai của Sinh viên B nhận HTTP 404; từ chối mọi request chứa trường `student_id` trong body (HTTP 400); Admin gọi endpoint sinh viên nhận HTTP 403; Sinh viên gọi endpoint admin nhận HTTP 403.

### Frontend

Mở ứng dụng với tham số kiểm thử:

```text
http://localhost/tuition-system/?test=1
```

Test runner frontend nằm tại `tests/tests.js` và kết quả kiểm thử được hiển thị trực tiếp trong Console của trình duyệt (`F12`).

## Checklist kiểm thử thủ công

| STT | Kịch bản | Kết quả kỳ vọng |
| :---: | --- | --- |
| 1 | Đăng nhập bằng `admin` / `Admin@123`. | Đăng nhập thành công; màn hình đăng nhập được ẩn và giao diện quản trị hiển thị tên "Quản trị viên Admin". |
| 2 | Đăng nhập bằng tên người dùng hoặc mật khẩu không hợp lệ. | Hiển thị thông báo chung: *"Sai tên đăng nhập hoặc mật khẩu."*; không tiết lộ tài khoản có tồn tại hay không. |
| 3 | Nhập sai mật khẩu liên tiếp 5 lần cho tài khoản `admin`. | Tài khoản bị khóa tạm thời 15 phút. Lần thử tiếp theo hiển thị thông báo khóa tài khoản (HTTP 409). |
| 4 | Đăng xuất rồi nhấn nút **Back** của trình duyệt. | Không hiển thị dữ liệu nhạy cảm; ứng dụng yêu cầu đăng nhập lại. |
| 5 | Truy cập `/api/students` hoặc `/api/fee-records/1` khi chưa đăng nhập. | Máy chủ trả HTTP 401 cùng thông báo *"Vui lòng đăng nhập để tiếp tục."* và không trả dữ liệu. |
| 6 | Thanh toán đúng bằng số dư còn nợ. | Giao dịch được lưu; hồ sơ chuyển sang trạng thái **Đã thanh toán đủ** và biên lai được hiển thị. |
| 7 | Thanh toán thiếu đúng 1 VND so với số còn nợ. | Giao dịch thành công; hồ sơ ở trạng thái **Thanh toán một phần**, còn nợ 1 VND. |
| 8 | Thanh toán nhiều hơn số còn nợ 1 VND. | Giao dịch bị từ chối với thông báo *"Số tiền vượt quá số còn nợ. Vui lòng lập yêu cầu hoàn tiền trước."*; không ghi thêm bản ghi vào sổ cái. |
| 9 | Thêm miễn giảm vào hồ sơ đã thanh toán đủ, khiến số đã nộp lớn hơn số phải nộp. | Thao tác được chấp nhận; hồ sơ chuyển sang trạng thái **Thừa tiền** (`OVERPAID`) và không nhận thêm thanh toán. |
| 10 | Tạo yêu cầu hoàn tiền cho khoản nộp thừa, vào "Thu học phí & Hoàn trả" > "Yêu cầu hoàn tiền" > "Hoàn tiền chờ duyệt" (`#refunds/pending`) rồi duyệt hoàn tiền. | Yêu cầu chuyển từ `pending` sang `completed`; tổng tiền đã nộp giảm và trạng thái nộp thừa được xử lý. |
| 11 | Tạo yêu cầu hoàn tiền rồi hủy yêu cầu tại "Thu học phí & Hoàn trả" > "Yêu cầu hoàn tiền" > "Hoàn tiền chờ duyệt" (`#refunds/pending`). | Yêu cầu chuyển sang `cancelled`, có ghi nhận lý do hủy; công nợ không thay đổi. |
| 12 | Chọn phiếu thu trong lịch sử và thực hiện **Đảo mục (Hủy)**. | Hệ thống yêu cầu lý do, tạo bút toán đảo mục đối ứng và khôi phục số dư nợ về mức trước giao dịch. |
| 13 | Nhấp đúp nhanh vào nút **Ghi nhận phiếu thu**. | Nút gửi bị vô hiệu hóa trong lúc xử lý; `request_id` bảo đảm không tạo giao dịch trùng. |
| 14 | Ngắt mạng khi nhấn Lưu, sau đó kết nối lại và thử lại. | Hiển thị thông báo lỗi mạng bằng tiếng Việt; dữ liệu đã nhập trên biểu mẫu được giữ lại. |
| 15 | Tìm sinh viên bằng từ khóa `nguyen` hoặc `Nguyễn`. | Cả hai từ khóa đều tìm được sinh viên họ Nguyễn, gồm SV001 Nguyễn Văn An và SV005 Nguyễn Thị Hòa. |
| 16 | Lọc lịch sử giao dịch theo khoảng ngày và học kỳ. | Danh sách sổ cái chỉ hiển thị giao dịch thuộc khoảng thời gian và học kỳ đã chọn. |
| 17 | Kiểm tra thẻ Thống kê và Báo cáo đối soát công nợ. | Phương trình `Đã thực thu + Còn phải thu = Tổng phải thu` cân bằng và hiển thị nhãn **Khớp 100%**. |
| 18 | Xem trước và in biên lai. | Biên lai có số biên lai, số tiền bằng số, số tiền bằng chữ và số dư còn nợ. Khi in, CSS `@media print` chỉ hiển thị mẫu biên lai. |
| 19 | Tải lại trang hoặc mở lại trình duyệt khi đã đăng nhập. | Phiên làm việc được duy trì bằng cookie session; dữ liệu tài chính được tải từ cơ sở dữ liệu. |
| 20 | Dừng Apache trong XAMPP rồi thao tác trên giao diện. | Hiển thị thông báo *"Không thể kết nối đến máy chủ. Vui lòng kiểm tra lại kết nối mạng hoặc XAMPP."*; dữ liệu đang nhập không bị mất. |
| 21 | Dừng MySQL trong XAMPP rồi tải lại trang. | Hiển thị thông báo *"Đã xảy ra lỗi hệ thống. Vui lòng thử lại."*; không để lộ mã nguồn, câu lệnh SQL hoặc stack trace. |
| 22 | Kiểm tra giao diện ở chiều rộng màn hình 360px. | Bố cục responsive; thanh điều hướng và bảng dữ liệu có thể cuộn ngang mà không làm vỡ giao diện. |
| 23 | Tạo lại hồ sơ học phí cho SV001 trong HK1 2026-2027. | Thông báo hồ sơ trùng lặp xuất hiện, giá trị trên biểu mẫu được giữ nguyên, nhấn "Mở hồ sơ hiện có" sẽ mở hồ sơ tương ứng. |
| 24 | Mở hồ sơ SV007 trong HK2 2025-2026. | Cảnh báo nộp thừa (Overpaid) hiển thị, nhấn "Xử lý số tiền thừa" hiển thị số dư thừa khả dụng là 700.000 VND. |
| 25 | Chuyển 700.000 VND từ HK2 2025-2026 sang HK1 2026-2027 của SV007. | Hộp thoại xác nhận hiển thị bằng tiếng Việt; sau khi đồng ý, chuyển tiền thành công, hồ sơ HK2 chuyển sang Đã thanh toán đủ (đã nộp 7.500.000 VND), hồ sơ HK1 giảm còn nợ xuống 9.500.000 VND. |
| 26 | Thực hiện hoàn tiền 700.000 VND trên bản dữ liệu mới của SV007 HK2 2025-2026. | Yêu cầu hoàn tiền ở trạng thái chờ duyệt (pending); hồ sơ vẫn giữ cảnh báo nộp thừa cho đến khi duyệt hoàn tất. |
| 27 | Hủy yêu cầu hoàn tiền đang chờ duyệt của SV007. | Yêu cầu chuyển sang đã hủy; số tiền nộp thừa khả dụng quay trở lại mức 700.000 VND. |
| 28 | Thử đảo bút toán khoản thu 8.200.000 VND sau khi đã chuyển tiền. | Bị từ chối với thông báo "Không thể đảo bút toán vì số tiền đã được hoàn hoặc chuyển đi." và tổng tiền nộp không bị âm. |
| 29 | Tìm kiếm SV009 "Đoàn Văn Ơn Ích" bằng từ khóa `don` và `đoàn`. | Cả hai từ khóa đều tìm được đúng SV009 nhờ cơ chế tìm kiếm không dấu mở rộng. |
| 30 | Kiểm tra hiển thị bảng sinh viên với tên 40 ký tự của SV010. | Hiển thị đầy đủ tên SV010 "Trương Thị Ánh Tuyết Hoàng Thị Hồng Loan", bảng dữ liệu co giãn chuẩn, không tràn viền. |
| 31 | Xóa sinh viên SV011 (lớp NULL, chưa có hồ sơ học phí). | Xóa thành công; sinh viên bị xóa hoàn toàn khỏi hệ thống mà không vướng ràng buộc khóa ngoại. |
| 32 | Xem chi tiết và lịch sử thanh toán của SV012 "Bùi Hữu Thắng". | Hiển thị hồ sơ học phí (miễn giảm 50%), gồm 2 khoản nộp tiền và 1 hoàn tiền với số dư nợ lũy kế khớp 100%. |
| 33 | Thêm sinh viên với mã viết thường `sv013`. | Hệ thống tự động chuẩn hóa và lưu trữ thành chữ in hoa `SV013`. |
| 34 | Nhập dữ liệu không hợp lệ (số tiền sai định dạng, ngày tương lai, ký tự đặc biệt). | Thông báo lỗi inline hiển thị trực tiếp dưới từng trường nhập với viền đỏ và icon ⚠️; focus tự động về trường lỗi đầu tiên; thông báo tự xóa khi nhập giá trị hợp lệ. |

## Quy trình thiết lập tài khoản sinh viên & 4 chức năng Cổng Sinh viên

### 1. Quy trình thiết lập tài khoản sinh viên (Student Account Setup Steps)
1. **Quản trị viên cấp tài khoản mới**:
   - Quản trị viên đăng nhập hệ thống (`admin` / `Admin@123`), chuyển đến tab **Sinh viên**.
   - Tại danh sách sinh viên, nhấn nút **🔑 Tài khoản** trên dòng của sinh viên cần cấp.
   - Hộp thoại quản lý tài khoản mở ra: nếu sinh viên chưa có tài khoản, nhấn **"Cấp tài khoản mới"**. Hệ thống tạo tài khoản:
     - `username`: mã sinh viên viết thường (ví dụ: `sv001`, `sv011`), lưu trữ chuẩn hóa.
     - Mật khẩu tạm thời: chuỗi 12 ký tự ngẫu nhiên kết hợp chữ hoa, chữ thường, chữ số và ký tự đặc biệt sinh bằng `random_bytes`, băm bằng `password_hash`.
     - Cờ `must_change_password = 1`.
   - Mỗi sinh viên chỉ có duy nhất 1 tài khoản; tạo trùng sẽ nhận lỗi HTTP 409: *"Sinh viên này đã có tài khoản."*
   - Quản trị viên sao chép mật khẩu tạm thời và gửi cho sinh viên (mật khẩu hiển thị 1 lần duy nhất trong phản hồi). Cả hai hành động tạo và đặt lại mật khẩu đều được ghi nhật ký kiểm toán (Audit Log).
   - *(Khởi tạo hàng loạt mẫu)*: Sử dụng lệnh CLI `php database/seed_users.php`.
2. **Quản trị viên đặt lại mật khẩu**:
   - Khi sinh viên cần cấp lại mật khẩu, quản trị viên mở hộp thoại **🔑 Tài khoản** của sinh viên đó và bấm **"Đặt lại mật khẩu"**.
   - Hệ thống sinh mật khẩu tạm thời 12 ký tự mới tương tự và bật lại cờ `must_change_password = 1`.

### 2. Bốn chức năng nghiệp vụ của Cổng Sinh viên
1. **Đăng ký tín chỉ (Credit Registration):**
   - Sinh viên chỉ xem được các học kỳ đang mở đăng ký (`registration_open = 1` và ngày hiện tại ≤ `registration_close`).
   - Nhập số tín chỉ trong giới hạn cấu hình (`student_settings`: từ 1 đến 30).
   - Khung xem trước tính toán tức thời học phí gốc (Gross) và học phí thực nộp (Net) bằng thư viện `calc.js`.
   - Sinh viên được phép điều chỉnh số tín chỉ khi chưa phát sinh thanh toán (`total_paid = 0`). Sau khi đã nộp tiền, việc thay đổi tín chỉ bị khóa (HTTP 422).
2. **Tra cứu công nợ học phí (Tuition Debt):**
   - Hiển thị tổng nợ tồn đọng trên tất cả các học kỳ.
   - Bảng *"Học phí chưa thanh toán"* lọc riêng các học kỳ có số nợ > 0 kèm nút "Nộp ngay".
   - Bảng toàn bộ hồ sơ hiển thị đầy đủ chi tiết: số tín chỉ, đơn giá snapshot, Gross, miễn giảm (số tiền + lý do), Net, đã nộp, còn nợ, trạng thái thanh toán và cảnh báo nộp thừa nếu có.
3. **Thanh toán học phí trực tuyến (Tuition Payment):**
   - Thanh toán qua cổng mô phỏng (`SimulatedGateway`), hỗ trợ 2 phương thức: **Chuyển khoản** và **Thẻ** (không hỗ trợ Tiền mặt cho sinh viên).
   - Nút thanh toán tự động vô hiệu hóa trong lúc giao dịch xử lý để chống nhấp đúp.
   - Xử lý nguyên tử với khóa dòng `SELECT ... FOR UPDATE`, sinh mã giao dịch `GW-YYYYMMDD-HEX` và số biên lai `PT-YYYYMMDD-NNNN`.
   - Đảm bảo tính bất biến trùng lặp (Idempotent by `request_id`). Khi thành công, hiển thị biên lai và liên kết xem / in biên lai.
4. **Lịch sử thanh toán & Biến động số dư (Payment History):**
   - Liệt kê các giao dịch thu tiền, đảo mục, chuyển tiền và các yêu cầu hoàn tiền đã hoàn tất kèm số dư lũy kế từng học kỳ.
   - Hiển thị nhãn tiếng Việt chuẩn: *"Thanh toán"*, *"Đảo bút toán"*, *"Nhận chuyển khoản"*, *"Chuyển đi"*, *"Hoàn tiền"*.
   - Yêu cầu hoàn tiền đang chờ duyệt hiển thị cảnh báo riêng (*"Yêu cầu hoàn tiền đang chờ duyệt"*), không bị tính lẫn vào giao dịch hoàn tất.
   - Bộ lọc khoảng ngày (chặn `to < from` và khoảng thời gian > 5 năm) và hỗ trợ in biên lai cho các dòng thanh toán (`payment`).

---

## Bảng kiểm thử thủ công phân hệ sinh viên (Hand-test Table)

| STT | Các bước thực hiện (Steps) | Kết quả kỳ vọng (Expected result) |
| :---: | --- | --- |
| 1 | **student login with correct and wrong password**<br>1. Tại màn hình đăng nhập, nhập `sv002` và mật khẩu sai `WrongPass@123`, nhấn Đăng nhập.<br>2. Nhập lại mật khẩu đúng `Student@002`, nhấn Đăng nhập. | - Khi nhập sai: Hiển thị thông báo lỗi chung *"Sai tên đăng nhập hoặc mật khẩu."*, không tiết lộ tài khoản có tồn tại hay không.<br>- Khi nhập đúng: Đăng nhập thành công, chuyển hướng vào Cổng sinh viên, hiển thị thông tin sinh viên `Trần Thị Bình (SV002)` và chỉ xuất hiện đúng 4 chức năng sinh viên. |
| 2 | **forced password change**<br>1. Đăng nhập tài khoản `sv006` / `Student@006` (tài khoản có `must_change_password = 1`).<br>2. Thử truy cập các chức năng khác hoặc gửi request API khác.<br>3. Thử đổi mật khẩu trùng mật khẩu tạm thời `Student@006` hoặc mật khẩu yếu (dưới 8 ký tự).<br>4. Nhập mật khẩu mới hợp lệ (ví dụ: `NewPass@2026`, gồm chữ và số, `>= 8 ký tự`), xác nhận khớp và gửi. | - Sau khi đăng nhập, hệ thống khóa toàn bộ các tab chức năng và chỉ hiển thị form "Yêu cầu Đổi mật khẩu".<br>- Mọi endpoint sinh viên khác đều trả về HTTP 403 với mã `PASSWORD_CHANGE_REQUIRED`.<br>- Tái sử dụng mật khẩu tạm thời bị từ chối với lỗi: *"Mật khẩu mới không được trùng với mật khẩu hiện tại/tạm thời."*<br>- Đổi mật khẩu thành công: Cờ `must_change_password` xóa về 0, phiên làm việc được cấp mới (`session_regenerate_id`) và chuyển vào Cổng sinh viên bình thường. |
| 3 | **register credits for HK1 2026-2027 and see the preview**<br>1. Đăng nhập sinh viên chưa có hồ sơ `sv011` / `Student@011`.<br>2. Mở tab "Đăng ký tín chỉ", chọn học kỳ HK1 2026-2027 (đang mở đăng ký).<br>3. Nhập số tín chỉ `15`. | - Thẻ học kỳ hiển thị rõ hạn chót đăng ký và định mức học phí (850.000 ₫/tín chỉ).<br>- Khung xem trước tính toán tức thời bằng `calc.js` hiển thị: Gross tạm tính = `12.750.000 ₫` và Net tạm tính = `12.750.000 ₫`.<br>- Bấm "Xác nhận Đăng ký Tín chỉ": Tạo mới hồ sơ học phí thành công (HTTP 201), hiển thị trạng thái đã đăng ký 15 tín chỉ. |
| 4 | **try credits 0 and 31**<br>1. Tại ô số tín chỉ đăng ký của HK1 2026-2027, nhập `0` rồi nhấn Đăng ký.<br>2. Nhập `31` rồi nhấn Đăng ký. | - Cả hai lần đều bị chặn inline ngay trên form và máy chủ trả về HTTP 400 kèm trường lỗi `credits`: *"Số tín chỉ phải từ 1 đến 30."*<br>- Không có bản ghi hồ sơ học phí nào được tạo hoặc cập nhật trên cơ sở dữ liệu. |
| 5 | **try to register HK2 2025-2026 (closed)**<br>1. Kiểm tra danh sách học kỳ trên giao diện Cổng sinh viên.<br>2. Gửi request API: `POST /api/student/registrations` với `semester_id = 2` (HK2 2025-2026 đã đóng đăng ký). | - Học kỳ đã đóng đăng ký không xuất hiện trong danh sách học kỳ mở trên giao diện.<br>- Request gửi lên máy chủ bị từ chối với HTTP 422 và thông báo: *"Học kỳ này đã đóng đăng ký."* |
| 6 | **pay exact debt for SV002 with Thẻ**<br>1. Đăng nhập tài khoản `sv002` / `Student@002` (còn nợ 10.300.000 ₫ tại HK1 2026-2027).<br>2. Vào tab "Thanh toán học phí", chọn hồ sơ còn nợ.<br>3. Chọn phương thức "Thẻ", giữ nguyên số tiền đúng bằng dư nợ `10.300.000 ₫`.<br>4. Nhấn nút "Xác nhận Thanh toán". | - Nút thanh toán bị vô hiệu hóa trong khi máy chủ và cổng thanh toán mô phỏng xử lý.<br>- Giao dịch hoàn tất thành công (HTTP 201), sinh mã tham chiếu `GW-...` và số biên lai `PT-YYYYMMDD-NNNN`.<br>- Hồ sơ chuyển sang trạng thái **Đã thanh toán đủ** (Fully Paid), hiển thị nút "Xem & In Biên lai". |
| 7 | **pay more than outstanding**<br>1. Với hồ sơ SV002 còn nợ 10.300.000 ₫, nhập số tiền thanh toán là `10.300.001 ₫` (vượt 1 đồng).<br>2. Nhấn nút "Xác nhận Thanh toán". | - Máy chủ từ chối với HTTP 422 và thông báo: *"Số tiền vượt quá số còn nợ."*<br>- Không có giao dịch nào được ghi vào sổ cái và không trừ tiền qua cổng thanh toán. |
| 8 | **student A tries to view student B's record by editing the URL**<br>1. Đăng nhập sinh viên `sv001`.<br>2. Thử nộp tiền vào hồ sơ của SV002: `POST /api/student/fee-records/2/payments`.<br>3. Thử tra cứu biên lai của SV002: `GET /api/receipts/PT-20260905-0001`.<br>4. Thử điều chỉnh đăng ký của SV002: `PUT /api/student/registrations/3` (khi SV006 chưa đăng ký kỳ 3). | - Mọi request truy vấn hoặc can thiệp vào dữ liệu của sinh viên khác đều bị máy chủ trả về **HTTP 404** (*"Không tìm thấy hồ sơ học phí."* hoặc *"Không tìm thấy biên lai thanh toán."*).<br>- Tuyệt đối không để lộ sự tồn tại hay dữ liệu tài chính của sinh viên khác. |
| 9 | **print a student receipt**<br>1. Sau khi thanh toán thành công hoặc vào tab "Lịch sử thanh toán", bấm nút "🖨️ In biên lai" tại một dòng giao dịch thu tiền (`payment`).<br>2. Xem trước mẫu in biên lai và bấm In. | - Khung biên lai điện tử mở ra với đầy đủ thông tin: Mã SV, Họ tên, Học kỳ, Phương thức, Số tiền bằng số, Số tiền bằng chữ tiếng Việt chuẩn, Số dư nợ còn lại.<br>- Khi xem trước trang in (Print Preview), CSS `@media print` chỉ hiển thị mẫu biên lai, ẩn toàn bộ thanh điều hướng, nút đóng và các thành phần thừa trên màn hình. |
| 10 | **history filter with to < from**<br>1. Vào tab "Lịch sử thanh toán".<br>2. Chọn Từ ngày: `01/10/2026`, Đến ngày: `01/09/2026`.<br>3. Nhấn nút "🔍 Lọc dữ liệu". | - Hệ thống từ chối lọc và hiển thị thông báo lỗi inline trực tiếp: *"Đến ngày không được nhỏ hơn từ ngày."*<br>- Máy chủ từ chối với HTTP 400 và trường lỗi `to`. |
| 11 | **admin screen still works for admin accounts**<br>1. Đăng nhập với tài khoản quản trị viên `admin` / `Admin@123`.<br>2. Kiểm tra các chức năng quản trị: Quản lý sinh viên, Cấp tài khoản sinh viên, Lập hồ sơ học phí, Miễn giảm, Thu học phí & Hoàn trả (Toàn bộ giao dịch, Yêu cầu hoàn tiền: Hoàn tiền chờ duyệt, Hồ sơ chờ duyệt), Sổ cái giao dịch, Báo cáo đối soát tài chính. | - Toàn bộ chức năng quản trị viên hoạt động chuẩn xác, giữ nguyên 100% hành vi ban đầu.<br>- Quản trị viên gọi các endpoint sinh viên `/api/student/*` đều nhận HTTP 403 (bị từ chối). |
| 12 | **student logs out then presses Back**<br>1. Sinh viên `sv001` đăng nhập, kiểm tra thông tin công nợ.<br>2. Nhấn nút "Đăng xuất".<br>3. Sau khi đã về màn hình đăng nhập, nhấn nút **Back** trên thanh công cụ của trình duyệt. | - Cơ chế `popstate` và `pageshow` kích hoạt kiểm tra phiên làm việc qua `GET /api/me`.<br>- Phiên đã bị hủy trên máy chủ, giao diện lập tức ẩn toàn bộ container sinh viên và giữ nguyên màn hình đăng nhập, không để lộ dữ liệu từ cache trình duyệt. |

## Giới hạn đã biết

- **Lưu phiên:** Ứng dụng sử dụng cơ chế session lưu bằng tệp mặc định của PHP thông qua `session_save_path()`.
- **HTTPS trên XAMPP cục bộ:** Môi trường XAMPP mặc định chưa cấu hình SSL/HTTPS. Cờ `Secure` của cookie session được tắt khi kết nối không sử dụng HTTPS và chỉ bật khi phát hiện kết nối bảo mật qua cổng HTTPS (443).
- **Cổng thanh toán trực tuyến mô phỏng (Simulated Gateway):** Hệ thống tích hợp dịch vụ `SimulatedGateway` (`api/lib/payment_gateway.php`) mô phỏng quy trình xử lý giao dịch thẻ và chuyển khoản ngân hàng, sinh mã tham chiếu giao dịch nguyên tử (`GW-YYYYMMDD-HEX`) và ghi nhận thành công cho các giao dịch hợp lệ. Cổng thanh toán thẻ hoặc ngân hàng thực tế (VNPAY, MoMo, OnePay, VietQR...) **chưa được tích hợp** trong phạm vi ứng dụng này.
- **Multi-tenancy:** Hệ thống hiện được thiết kế theo mô hình single-tenant, phục vụ một đơn vị đào tạo.
- **Chuyển số tiền thành chữ:** Chức năng hỗ trợ tiếng Việt cho các giá trị VND từ hàng đơn vị đến hàng trăm tỷ đồng và có kiểm thử tự động.

## Trạng thái yêu cầu

Theo trạng thái được ghi nhận trong tài liệu dự án, toàn bộ các yêu cầu trong đặc tả kỹ thuật và nghiệp vụ đã được triển khai hoàn tất và kiểm thử nghiêm ngặt, bao gồm:

- Session, CSRF, phân quyền Quản trị viên (`admin`) và Sinh viên (`student`).
- Phân hệ Cổng Sinh viên độc lập (4 chức năng: Đăng ký tín chỉ, Tra cứu công nợ, Thanh toán qua cổng mô phỏng, Lịch sử giao dịch).
- Cơ chế bắt buộc đổi mật khẩu lần đầu (`must_change_password`).
- Kiểm soát đồng thời bằng `FOR UPDATE`.
- Idempotency bằng UUID `request_id`.
- Chuẩn hóa tìm kiếm tiếng Việt không dấu.
- In biên lai điện tử chuẩn quy chế đào tạo.
- Toàn bộ bài kiểm thử tự động tích hợp đạt kết quả xuất sắc.

Tài liệu hiện ghi nhận không có yêu cầu nào bị bỏ sót. Kết luận này phản ánh trạng thái dự án được mô tả trong README; cần đối chiếu với mã nguồn và kết quả chạy kiểm thử thực tế khi đánh giá một phiên bản cụ thể.
