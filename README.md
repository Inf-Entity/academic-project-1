# Student Tuition Management System

The Student Tuition Management System is a lightweight, web-based platform designed for universities to manage academic tuition fees, student billing, and payment tracking. It provides university administrators with complete control over credit tuition rates, exemptions, payment recording, and financial reconciliation. For students, it delivers a secure self-service portal to review outstanding fee balances, register course credits, and complete simulated tuition payments with instant electronic billing.

---

## 1. Who Uses It: Roles & Access Control

The application provides strict role-based access control, isolating administrative capabilities from student self-service:

| Feature / Capability | University Administrator (`admin`) | Student (`student`) |
| :--- | :---: | :---: |
| **Manage Students & Semesters** | Full control (Create, Update, Delete) | Read-only access to open registration periods |
| **Set Tuition Rates & Exemptions** | Full control (Credit price, fixed/% discounts) | No access (HTTP 403 Forbidden) |
| **Record Cash & Bank Payments** | Full control (Issues official `PT-` receipts) | No access (HTTP 403 Forbidden) |
| **Manage Refunds & Transfers** | Full control (Maker-checker review queue) | View status of own pending refund requests |
| **Financial Reports & Audit Logs** | Full access to real-time dashboards & logs | No access (HTTP 403 Forbidden) |
| **Course Credit Registration** | Can adjust student credits before payment | Self-service registration during open windows |
| **Online Tuition Payment** | Simulated admin cash collection | Simulated **QR Code** & **Visa/Mastercard** payments |
| **Electronic Bills (`HD-`)** | View and audit transaction records | View, download, and print official HTML bills |
| **Data Privacy & Isolation** | School-wide aggregated view | Strict isolation (Cannot view other students' data) |

---

## 2. Quick Start Guide (5 Easy Steps)

Get the application up and running on your local machine in under 5 minutes:

1. **Install XAMPP**: Download and install [XAMPP for Windows](https://www.apachefriends.org/) (PHP 8.1+ and MySQL/MariaDB). Start both the **Apache** and **MySQL** services in the XAMPP Control Panel.
2. **Create root database**: Navigate to:
   ```text
   http://localhost/phpmyadmin
   ```
   Create a database named `tuition_system` with the collation `utf8mb4_unicode_ci` (if it does not already exist).
3. **Deploy Project Files**: Copy or clone this project repository into your XAMPP web root folder at `C:\xampp\htdocs\tuition-system` (or `C:\xampp\htdocs\bigprojectnew`).
4. **Initialize Database**: Open your terminal (PowerShell or Command Prompt) and execute the setup scripts:
   ```powershell
   cd C:\xampp\htdocs\tuition-system
   C:\xampp\mysql\bin\mysql.exe -u root < database/create_app_user.sql
   C:\xampp\mysql\bin\mysql.exe -u root tuition_system < database/schema.sql
   C:\xampp\mysql\bin\mysql.exe -u root tuition_system < database/seed.sql
   C:\xampp\php\php.exe database/seed_users.php --db=tuition_system
   ```
5. **Launch Application in Browser**: Open Google Chrome, Microsoft Edge, or Mozilla Firefox and navigate to:
   ```text
   http://localhost/tuition-system/
   ```
6. **Log In and Explore**: Use one of the pre-configured demo accounts below to explore administrator dashboards or student self-service portals.

> [!TIP]
> For advanced configuration, automated test suites, backup scripts, and troubleshooting, consult the comprehensive [Installation Guide](file:///c:/Users/infin/Documents/antigravity/bigprojectnew/docs/Installation_Guide.docx).

---

## 3. Demo Accounts & Security Warning

The system comes pre-seeded with test accounts covering realistic student and administrator scenarios:

| Username | Password | Role | Description & Initial State |
| :--- | :--- | :--- | :--- |
| `admin` | `Admin@123` | Administrator | Head Finance Officer; full administrative and approval rights |
| `staff01` | `Staff@001` | Administrator | Finance Clerk; can record payments and submit refund requests |
| `sv001` | `Student@001` | Student | Nguyễn Văn An (Class K22-CNTT1); tuition fully paid, has excess balance |
| `sv002` | `Student@002` | Student | Trần Thị Bích (Class K22-CNTT1); partially paid, outstanding balance |
| `sv006` | `Student@006` | Student | First-time login demo; requires immediate password change |

> [!CAUTION]
> **Security Notice**: These pre-configured demo credentials are for local development, academic evaluation, and demonstration purposes only. Before deploying to any staging or production intranet, change all default passwords and update the application secret keys in `config/config.php`.

---

## 4. Common Problems & Easy Solutions

| Issue Symptom | Root Cause | Easy One-Step Solution |
| :--- | :--- | :--- |
| **Vietnamese characters show as question marks (`?`)** | Database connection or table collation is not using UTF-8 Unicode. | Ensure MariaDB database and connection collation is set to `utf8mb4_unicode_ci` as configured in `config/config.php`. |
| **Blank white page when opening the website** | Apache rewrite engine is not enabled or PHP short tags are disabled. | Verify `mod_rewrite` is enabled in `C:\xampp\apache\conf\httpd.conf` and restart the Apache service. |
| **Account is locked after multiple failed logins** | 5 consecutive wrong passwords trigger an automated 15-minute security lockout. | Wait 15 minutes, or ask an administrator to reset `failed_logins = 0, locked_until = NULL` in the `users` table. |
| **QR Code image does not render during payment** | Browser blocked inline SVG/Canvas or local JS script failed to load. | Check that `src/js/qrcode.js` is loaded properly and reload the browser page (`Ctrl + F5`). |
| **Apache or MySQL fails to start in XAMPP** | Port conflict (Port 80 occupied by IIS/Skype, or Port 3306 occupied by another MySQL service). | In XAMPP Control Panel, click **Config** $\to$ **Service and Port Settings**, or stop conflicting background services. |

---

## 5. Hand-Test Checklist (15 Essential Scenarios)

Use this quick manual verification checklist to test key application features:

1. **Administrator Login**:
   - *Steps*: Open `http://localhost/tuition-system/`, enter `admin` / `Admin@123`, and click **Đăng nhập**.
   - *Expected Result*: Redirects to the Administrative Dashboard; displays tuition summary KPI cards and navigation menu.
2. **Student Portal Login**:
   - *Steps*: Log out, then log in using `sv002` / `Student@002`.
   - *Expected Result*: Displays the student dashboard showing personal fee records and course registrations; administrator menus are hidden.
3. **Mandatory First-Time Password Change**:
   - *Steps*: Log in as `sv006` / `Student@006`.
   - *Expected Result*: System immediately presents a mandatory password change modal; navigation to other menus is blocked until updated.
4. **Course Credit Registration**:
   - *Steps*: As student `sv002`, navigate to **Đăng ký tín chỉ**, select open semester `HK1 2026-2027`, enter `15` credits, and submit.
   - *Expected Result*: A new fee record is created with Gross Tuition equal to $15 \times \text{Rate Snapshot}$ VND.
5. **Fee Calculation & Exemption Verification**:
   - *Steps*: As admin, view fee record #1 (Nguyễn Văn An).
   - *Expected Result*: Net Due equals Gross Tuition minus applied 10% exemption ($12,750,000 - 1,275,000 = 11,475,000$ VND).
6. **Cash Payment Recording**:
   - *Steps*: As admin, open fee record #2, enter payment amount `2,000,000` VND via method **Tiền mặt**, and submit.
   - *Expected Result*: Payment is recorded, an official `PT-YYYYMMDD-NNNN` receipt is generated, and remaining balance decreases by 2,000,000 VND.
7. **Overpayment Rejection (Server-Enforced)**:
   - *Steps*: On fee record #2, attempt to pay 1 VND more than the outstanding debt.
   - *Expected Result*: Transaction is rejected with HTTP 422 and a clear Vietnamese error message: payment exceeding balance is not permitted.
8. **Tuition Rate Snapshot Freeze**:
   - *Steps*: As admin, update the credit rate for Semester 3 from 850,000 to 900,000 VND.
   - *Expected Result*: Existing fee records retain their original 850,000 VND rate snapshot; only new fee records receive the 900,000 VND rate.
9. **Simulated QR Code Payment**:
   - *Steps*: As student `sv002`, select **Thanh toán học phí**, choose **Mã QR**, enter amount `1,000,000` VND, and click **Tạo mã QR**.
   - *Expected Result*: A dynamic QR code is displayed with payload `TUITION|SESSION=...|FEE=...|AMT=1000000`.
10. **Simulated QR Confirmation & Instant Bill**:
    - *Steps*: Click **Mô phỏng thanh toán thành công** on the QR payment screen.
    - *Expected Result*: Status updates to `confirmed`, ledger records payment method `Mã QR`, and electronic bill `HD-YYYYMMDD-NNNN` is generated.
11. **Simulated Visa/Mastercard Payment**:
    - *Steps*: As student `sv002`, select **Thẻ Visa/Mastercard**, enter card `4111 1111 1111 1111`, expiry `12/28`, CVV `123`, and submit.
    - *Expected Result*: Payment is approved instantly; ledger note records `Thẻ Visa (*1111)`; zero card numbers or CVVs are saved to the database.
12. **Bill Download & Print**:
    - *Steps*: Click **Tải hóa đơn (HTML)** or **In hóa đơn** for the completed transaction.
    - *Expected Result*: Opens a standalone, script-free HTML invoice formatted with university header, payment details, and signature blocks.
13. **Data Privacy & IDOR Prevention**:
    - *Steps*: While logged in as `sv001`, attempt to access student `sv002`'s bill URL (`/api/student/bills/3`).
    - *Expected Result*: Access is denied with HTTP 404 Not Found, completely concealing the existence of the foreign record.
14. **Refund Request & Maker-Checker Separation**:
    - *Steps*: Log in as `staff01`, submit a refund request for an overpaid student, and attempt to approve it as `staff01`.
    - *Expected Result*: Self-approval is rejected with HTTP 403 Forbidden; only a different authorized officer (`admin`) can approve the refund.
15. **Vietnamese Accent-Insensitive Search**:
    - *Steps*: As admin, navigate to **Hồ sơ sinh viên**, search for `nguyen`, then search for `Nguyễn`.
    - *Expected Result*: Both searches return the exact same student records regardless of accent marks or uppercase/lowercase input.

---

## 6. Project Documentation Index

Comprehensive engineering, architecture, and quality assurance documents are located in the [`docs/`](file:///c:/Users/infin/Documents/antigravity/bigprojectnew/docs/) directory:

1. [**Requirements Analysis Document** (`docs/Requirements_Analysis.docx`)](file:///c:/Users/infin/Documents/antigravity/bigprojectnew/docs/Requirements_Analysis.docx): Comprehensive software requirements specification, actor definitions, bilingual glossary, functional requirements (`FR-001`+), measurable non-functional targets, and traceability matrix.
2. [**Entity-Relationship Diagram** (`docs/ERD.pdf`)](file:///c:/Users/infin/Documents/antigravity/bigprojectnew/docs/ERD.pdf): Architectural landscape A3 database diagram featuring crow's-foot notation, primary/foreign keys, entity cardinalities, and table row counts.
3. [**Use Case Diagram & Specs** (`docs/UseCase_Diagram.pdf`)](file:///c:/Users/infin/Documents/antigravity/bigprojectnew/docs/UseCase_Diagram.pdf): System boundary diagrams, actor interactions (Admin, Student, Payment Simulator), functional breakdown tree, and detailed use case specifications.
4. [**Installation & Operations Guide** (`docs/Installation_Guide.docx`)](file:///c:/Users/infin/Documents/antigravity/bigprojectnew/docs/Installation_Guide.docx): Complete administrator setup handbook covering XAMPP installation, database provisioning, automated test execution, daily operations, and backup/restore procedures.
5. [**Functional & Security Test Report** (`docs/Functional_Test_Report.docx`)](file:///c:/Users/infin/Documents/antigravity/bigprojectnew/docs/Functional_Test_Report.docx): Formal quality assurance report documenting 100% pass rates across 402 functional tests, 6 concurrency/performance benchmarks, and 46 security tests.

---

## 7. System Limitations

- **Simulated Payment Gateway**: Online QR and Visa/Mastercard payment methods are local architectural simulations designed for training, demonstration, and evaluation. No actual money is transferred, and no external bank or payment gateway APIs (e.g. VNPay, MoMo, Stripe) are connected.
- **Intranet / Local Environment**: The system is designed to operate on local HTTP or university intranet environments managed via XAMPP. Production public web deployments should configure HTTPS via TLS certificates and disable development debug flags.
- **Single Currency**: All financial figures and transactions are strictly denominated in integer Vietnamese Đồng (VND). Foreign exchange and decimal currencies are intentionally excluded by business design.
