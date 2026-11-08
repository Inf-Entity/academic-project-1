<?php
/**
 * Trang Giới thiệu - Hệ thống Quản lý Học phí Sinh viên
 */
declare(strict_types=1);
?>
<!DOCTYPE html>
<html lang="vi">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Giới thiệu - Hệ thống Quản lý Học phí Sinh viên</title>
  <link rel="stylesheet" href="../frontend/css/style.css">
  <style>
    .page-container {
      max-width: 1000px;
      margin: 2rem auto;
      padding: 0 1.5rem;
    }
    .about-card {
      background: #ffffff;
      border-radius: 8px;
      border: 1px solid #e5e7eb;
      padding: 2.5rem;
      box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
      margin-bottom: 2rem;
    }
    .feature-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
      gap: 1.5rem;
      margin-top: 1.5rem;
    }
    .feature-box {
      border: 1px solid #e5e7eb;
      border-radius: 6px;
      padding: 1.25rem;
      background: #f9fafb;
    }
    .feature-box h3 {
      font-size: 1.1rem;
      color: #1e3a8a;
      margin-bottom: 0.5rem;
      display: flex;
      align-items: center;
      gap: 0.5rem;
    }
    .nav-breadcrumb {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 1.5rem;
    }
  </style>
</head>
<body style="background-color: #f3f4f6; color: #1f2937;">

  <div class="page-container">
    <nav class="nav-breadcrumb">
      <a href="../index.php" class="btn btn-secondary">← Quay lại Hệ thống Học phí</a>
      <div style="display: flex; gap: 0.5rem;">
        <a href="about.php" class="btn btn-primary" style="pointer-events: none;">Giới thiệu</a>
        <a href="contact.php" class="btn btn-secondary">Liên hệ Hỗ trợ</a>
      </div>
    </nav>

    <div class="about-card">
      <div style="border-bottom: 2px solid #e5e7eb; padding-bottom: 1rem; margin-bottom: 1.5rem;">
        <span style="font-size: 0.875rem; text-transform: uppercase; letter-spacing: 0.05em; color: #2563eb; font-weight: 600;">Thông tin Hệ thống</span>
        <h1 style="font-size: 2rem; color: #1e3a8a; margin-top: 0.25rem;">Hệ thống Quản lý Học phí Sinh viên</h1>
        <p style="color: #4b5563; font-size: 1.05rem; margin-top: 0.5rem;">
          Giải pháp công nghệ tài chính giáo dục chuẩn mực, an toàn, minh bạch và tuân thủ chặt chẽ quy chế kế toán đại học.
        </p>
      </div>

      <section style="margin-bottom: 2rem;">
        <h2 style="font-size: 1.4rem; color: #1e3a8a; margin-bottom: 0.75rem;">1. Tổng quan &amp; Mục tiêu</h2>
        <p style="line-height: 1.6; color: #374151;">
          Hệ thống Quản lý Thu học phí Sinh viên được xây dựng nhằm tin học hóa và chuẩn hóa toàn diện quy trình theo dõi công nợ, quản lý định mức tín chỉ, xét duyệt miễn giảm và ghi nhận các luồng thu - hoàn tiền học phí trong các cơ sở giáo dục đại học và cao đẳng.
        </p>
        <p style="line-height: 1.6; color: #374151; margin-top: 0.5rem;">
          Hệ thống đảm bảo tính toàn vẹn dữ liệu tài chính ở mức cao nhất: số dư nợ và trạng thái thanh toán được suy diễn tự động trực tiếp từ sổ cái bất biến (append-only ledger), loại trừ triệt để tình trạng bất đồng bộ dữ liệu.
        </p>
      </section>

      <section style="margin-bottom: 2rem;">
        <h2 style="font-size: 1.4rem; color: #1e3a8a; margin-bottom: 0.75rem;">2. Các Phân hệ &amp; Tính năng Cốt lõi</h2>
        <div class="feature-grid">
          <div class="feature-box">
            <h3>🏷️ Bảo toàn Định mức (Rate Snapshot)</h3>
            <p style="font-size: 0.95rem; color: #4b5563; line-height: 1.5;">
              Mỗi học kỳ áp dụng một mức giá tín chỉ duy nhất. Khi định mức thay đổi, hồ sơ học phí đã lập giữ nguyên giá trị snapshot ban đầu, đảm bảo tính bất biến của hợp đồng đào tạo.
            </p>
          </div>

          <div class="feature-box">
            <h3>🎁 Quản lý Miễn giảm Học phí</h3>
            <p style="font-size: 0.95rem; color: #4b5563; line-height: 1.5;">
              Hỗ trợ 2 hình thức: theo tỷ lệ phần trăm (1-100%) hoặc số tiền cố định. Tự động làm tròn số tiền miễn giảm đến hàng đồng và cảnh báo khi số nộp đã vượt mức phải nộp mới.
            </p>
          </div>

          <div class="feature-box">
            <h3>🛡️ Kiểm soát Chặn Nộp thừa</h3>
            <p style="font-size: 0.95rem; color: #4b5563; line-height: 1.5;">
              Chặn nghiêm ngặt các khoản nộp vượt quá dư nợ thực tế. Áp dụng cơ chế khóa bi quan (SELECT FOR UPDATE) và xử lý tương tranh an toàn chống race-condition.
            </p>
          </div>

          <div class="feature-box">
            <h3>🔄 Quy trình Hoàn tiền 2 bước</h3>
            <p style="font-size: 0.95rem; color: #4b5563; line-height: 1.5;">
              Quy trình hoàn tiền minh bạch: Tạo yêu cầu (Chờ duyệt) &rarr; Xét duyệt và chi trả (Đã hoàn tiền). Chỉ khi hoàn tất mới hạch toán giảm tổng thu thực tế.
            </p>
          </div>

          <div class="feature-box">
            <h3>📑 Bút toán Đảo mục Bất biến</h3>
            <p style="font-size: 0.95rem; color: #4b5563; line-height: 1.5;">
              Các dòng giao dịch đã ghi sổ không bao giờ bị xóa hay sửa đổi. Mọi điều chỉnh được thực hiện thông qua bút toán đảo mục (reversal) có lưu vết người thực hiện và lý do chi tiết.
            </p>
          </div>

          <div class="feature-box">
            <h3>🖨️ Biên lai &amp; Đọc số tiền ra chữ</h3>
            <p style="font-size: 0.95rem; color: #4b5563; line-height: 1.5;">
              Biên lai điện tử đánh số tự động tuần tự theo ngày (PT-YYYYMMDD-NNNN), chuyển đổi chính xác số tiền VND sang câu chữ tiếng Việt chuẩn quy chuẩn tài chính ngân hàng.
            </p>
          </div>
        </div>
      </section>

      <section style="margin-bottom: 2rem;">
        <h2 style="font-size: 1.4rem; color: #1e3a8a; margin-bottom: 0.75rem;">3. Kiến trúc Công nghệ</h2>
        <ul style="line-height: 1.8; color: #374151; padding-left: 1.25rem;">
          <li><strong>Ngôn ngữ máy chủ:</strong> PHP 8.1+ (Chế độ Strict Types, bảo vệ chống Exception leak).</li>
          <li><strong>Cơ sở dữ liệu:</strong> MariaDB / MySQL 8.0+ (InnoDB Engine, utf8mb4_unicode_ci, View tính toán tổng hợp, Ràng buộc khóa ngoại ON DELETE RESTRICT).</li>
          <li><strong>Giao diện người dùng:</strong> Vanilla HTML5, CSS3 hiện đại, ES6+ JavaScript không phụ thuộc thư viện bên ngoài hay npm.</li>
          <li><strong>An toàn thông tin:</strong> Bảo vệ CSRF Token kép, Chống SQL Injection bằng Prepared Statements, Hash mật khẩu bcrypt, Cơ chế chống Brute-force tự động khóa tài khoản sau 5 lần sai.</li>
        </ul>
      </section>

      <div style="border-top: 1px solid #e5e7eb; padding-top: 1.5rem; text-align: center; color: #6b7280; font-size: 0.875rem;">
        <p>&copy; 2026 Hệ thống Quản lý Học phí Sinh viên. Bản quyền thuộc về Nhà trường.</p>
      </div>
    </div>
  </div>

</body>
</html>
