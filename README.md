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
     - **Bước 3:** Nhập `database/seed.sql` (Nạp dữ liệu mẫu chuẩn cho 9 bảng nghiệp vụ, gồm: 7 sinh viên, 12 hồ sơ học phí, 7 miễn giảm, 11 giao dịch sổ cái, 3 yêu cầu hoàn tiền).
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
| `admin` | `Admin@123` | `admin` | Kiểm thử các nghiệp vụ quản trị |
| `staff01` | `Staff@123` | `admin` | Kiểm thử người thao tác trong Audit Log và biên lai |

## Chạy kiểm thử tự động

### Backend

Chạy tại thư mục gốc của dự án:

```bash
php tests/run.php
```

Bộ kiểm thử sử dụng cơ sở dữ liệu riêng `tuition_test`, tự động reset bảng và nạp dữ liệu mẫu trước khi kiểm tra (xác minh số lượng chuẩn: 7 sinh viên, 12 hồ sơ học phí, 7 miễn giảm, 11 giao dịch sổ cái, 3 yêu cầu hoàn tiền). Theo cấu hình được mô tả trong dự án, test runner bao gồm 100 bài kiểm thử tích hợp backend thuộc 16 nhóm quy tắc bảo mật và nghiệp vụ.

Các nội dung được kiểm tra gồm:

- Tính học phí gốc, miễn giảm theo phần trăm hoặc số tiền cố định và quy tắc làm tròn.
- Từ chối khoản thanh toán vượt số tiền còn nợ và bảo toàn sổ cái khi giao dịch bị từ chối.
- Các trường hợp biên: thanh toán thiếu 1 đồng, thanh toán đủ, số phải thu bằng 0 và thanh toán vượt mức.
- Lưu snapshot đơn giá tín chỉ để không thay đổi khi đơn giá học kỳ được chỉnh sửa.
- Quy trình hoàn tiền; giới hạn số tiền hoàn theo khoản nộp thừa khả dụng.
- Bút toán đảo mục, ngăn đảo mục lặp lại và ngăn đảo mục của giao dịch đảo mục.
- Idempotency bằng `request_id` theo định dạng UUID.
- Đánh số biên lai nguyên tử theo ngày với định dạng `PT-YYYYMMDD-NNNN`; số thứ tự bắt đầu lại từ `0001` vào ngày mới.
- Kiểm thử đồng thời: hai tiến trình PHP cùng thanh toán một hồ sơ; chỉ một tiến trình được chấp nhận khi khoản còn nợ không đủ cho cả hai.
- Chuẩn hóa tìm kiếm tiếng Việt, bao gồm NFD/NFC và chữ hoa/chữ thường.
- Ràng buộc toàn vẹn khi xóa sinh viên đã phát sinh công nợ hoặc hồ sơ.
- Đối soát phương trình `Collected + Outstanding = Receivable`.
- Bảo mật phiên: HTTP 401 khi chưa xác thực, HTTP 403 khi CSRF Token thiếu hoặc không hợp lệ và khóa tài khoản 15 phút sau 5 lần đăng nhập sai.
- Kiểm tra dữ liệu nhập: chấp nhận định dạng `1.500.000` và `1,500,000`; từ chối số âm, số 0, số thập phân `1.5`, ký tự không hợp lệ và dữ liệu vượt giới hạn Unicode.
- Từ chối ngày thanh toán trong tương lai.

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
| 10 | Tạo yêu cầu hoàn tiền cho khoản nộp thừa rồi duyệt hoàn tiền. | Yêu cầu chuyển từ `pending` sang `completed`; tổng tiền đã nộp giảm và trạng thái nộp thừa được xử lý. |
| 11 | Tạo yêu cầu hoàn tiền rồi hủy yêu cầu. | Yêu cầu chuyển sang `cancelled`, có ghi nhận lý do hủy; công nợ không thay đổi. |
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

## Giới hạn đã biết

- **Lưu phiên:** Ứng dụng sử dụng cơ chế session lưu bằng tệp mặc định của PHP thông qua `session_save_path()`.
- **HTTPS trên XAMPP cục bộ:** Môi trường XAMPP mặc định chưa cấu hình SSL/HTTPS. Cờ `Secure` của cookie session được tắt khi kết nối không sử dụng HTTPS và chỉ bật khi phát hiện kết nối bảo mật qua cổng HTTPS (443).
- **Multi-tenancy:** Hệ thống hiện được thiết kế theo mô hình single-tenant, phục vụ một đơn vị đào tạo.
- **Chuyển số tiền thành chữ:** Chức năng hỗ trợ tiếng Việt cho các giá trị VND từ hàng đơn vị đến hàng trăm tỷ đồng và có kiểm thử tự động.

## Trạng thái yêu cầu

Theo trạng thái được ghi nhận trong tài liệu dự án, các yêu cầu trong đặc tả kỹ thuật và nghiệp vụ đã được triển khai và kiểm thử, bao gồm:

- Session, CSRF và phân quyền.
- Kiểm soát đồng thời bằng `FOR UPDATE`.
- Idempotency bằng UUID.
- Chuẩn hóa tìm kiếm tiếng Việt.
- In biên lai.
- Kiểm thử tự động cho các quy tắc nghiệp vụ và bảo mật.

Tài liệu hiện ghi nhận không có yêu cầu nào bị bỏ sót. Kết luận này phản ánh trạng thái dự án được mô tả trong README; cần đối chiếu với mã nguồn và kết quả chạy kiểm thử thực tế khi đánh giá một phiên bản cụ thể.
