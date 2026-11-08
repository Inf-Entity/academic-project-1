<?php
/**
 * Trang Liên hệ & Hỗ trợ - Hệ thống Quản lý Học phí Sinh viên
 */
declare(strict_types=1);

$submitted = false;
$feedbackMsg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $senderName = trim($_POST['sender_name'] ?? '');
    $studentCode = trim($_POST['student_code'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $content = trim($_POST['content'] ?? '');

    if ($senderName !== '' && $studentCode !== '' && $content !== '') {
        $submitted = true;
        $feedbackMsg = 'Cảm ơn bạn đã gửi phản hồi. Phòng Kế hoạch - Tài chính đã tiếp nhận thông tin và sẽ phản hồi trong vòng 24 giờ làm việc.';
    } else {
        $feedbackMsg = 'Vui lòng điền đầy đủ các trường thông tin bắt buộc.';
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Liên hệ &amp; Hỗ trợ - Phòng Kế hoạch Tài chính</title>
  <link rel="stylesheet" href="../frontend/css/style.css">
  <style>
    .page-container {
      max-width: 1000px;
      margin: 2rem auto;
      padding: 0 1.5rem;
    }
    .contact-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 2rem;
    }
    @media (max-width: 768px) {
      .contact-grid {
        grid-template-columns: 1fr;
      }
    }
    .contact-card {
      background: #ffffff;
      border-radius: 8px;
      border: 1px solid #e5e7eb;
      padding: 2rem;
      box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
    }
    .nav-breadcrumb {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 1.5rem;
    }
    .bank-info-box {
      background: #eff6ff;
      border: 1px solid #bfdbfe;
      border-radius: 6px;
      padding: 1.25rem;
      margin-top: 1rem;
    }
  </style>
</head>
<body style="background-color: #f3f4f6; color: #1f2937;">

  <div class="page-container">
    <nav class="nav-breadcrumb">
      <a href="../index.php" class="btn btn-secondary">← Quay lại Hệ thống Học phí</a>
      <div style="display: flex; gap: 0.5rem;">
        <a href="about.php" class="btn btn-secondary">Giới thiệu</a>
        <a href="contact.php" class="btn btn-primary" style="pointer-events: none;">Liên hệ Hỗ trợ</a>
      </div>
    </nav>

    <div class="contact-grid">
      <!-- Thông tin liên hệ trực tiếp -->
      <div class="contact-card">
        <h1 style="font-size: 1.5rem; color: #1e3a8a; margin-bottom: 1rem; border-bottom: 2px solid #e5e7eb; padding-bottom: 0.5rem;">
          Phòng Kế hoạch - Tài chính
        </h1>

        <p style="color: #4b5563; line-height: 1.6; margin-bottom: 1.25rem;">
          Bộ phận chuyên trách quản lý thu học phí, giải quyết các thủ tục miễn giảm, xác nhận hoàn thành nghĩa vụ tài chính và xử lý yêu cầu hoàn tiền cho sinh viên.
        </p>

        <div style="line-height: 1.8; color: #374151;">
          <p><strong>📍 Địa chỉ:</strong> Phòng A.102 - Tòa nhà Điều hành, Khuôn viên chính</p>
          <p><strong>🕒 Giờ tiếp sinh viên:</strong></p>
          <ul style="margin: 0.25rem 0 0.75rem 1.25rem; color: #4b5563;">
            <li>Buổi sáng: 07:30 - 11:30 (Thứ 2 đến Thứ 6)</li>
            <li>Buổi chiều: 13:00 - 17:00 (Thứ 2 đến Thứ 6)</li>
          </ul>
          <p><strong>☎️ Hotline giải đáp học phí:</strong> (028) 3823 4567 - Số máy lẻ: 108</p>
          <p><strong>📧 Email hỗ trợ:</strong> <a href="mailto:taichinh@truong.edu.vn" style="color: #2563eb;">taichinh@truong.edu.vn</a></p>
        </div>

        <div class="bank-info-box">
          <h3 style="color: #1e3a8a; margin-bottom: 0.5rem; font-size: 1.05rem;">🏦 Tài khoản Ngân hàng Thu Học phí</h3>
          <p style="font-size: 0.95rem; margin-bottom: 0.25rem;"><strong>Ngân hàng:</strong> Vietcombank - Chi nhánh TP.HCM</p>
          <p style="font-size: 0.95rem; margin-bottom: 0.25rem;"><strong>Số tài khoản:</strong> <code>0071 0008 99999</code></p>
          <p style="font-size: 0.95rem; margin-bottom: 0.25rem;"><strong>Chủ tài khoản:</strong> [Tên Trường Đại học]</p>
          <p style="font-size: 0.9rem; color: #1e40af; margin-top: 0.5rem;">
            <em>Cú pháp chuyển khoản bắt buộc:</em><br>
            <strong>[Mã SV] [Họ và tên] Hoc phi [Học kỳ]</strong><br>
            <em>(Ví dụ: SV001 Nguyen Van An Hoc phi HK1 26-27)</em>
          </p>
        </div>
      </div>

      <!-- Biểu mẫu gửi thắc mắc -->
      <div class="contact-card">
        <h2 style="font-size: 1.35rem; color: #1e3a8a; margin-bottom: 1rem; border-bottom: 2px solid #e5e7eb; padding-bottom: 0.5rem;">
          Gửi yêu cầu Hỗ trợ / Khiếu nại Học phí
        </h2>

        <?php if ($submitted): ?>
          <div style="background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46; padding: 1rem; border-radius: 6px; margin-bottom: 1rem;">
            <?= htmlspecialchars($feedbackMsg, ENT_QUOTES, 'UTF-8') ?>
          </div>
        <?php elseif ($feedbackMsg !== ''): ?>
          <div style="background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; padding: 1rem; border-radius: 6px; margin-bottom: 1rem;">
            <?= htmlspecialchars($feedbackMsg, ENT_QUOTES, 'UTF-8') ?>
          </div>
        <?php endif; ?>

        <form method="POST" action="contact.php" style="display: flex; flex-direction: column; gap: 1rem;">
          <div>
            <label for="sender_name" style="display: block; font-weight: 500; font-size: 0.9rem; margin-bottom: 0.25rem;">
              Họ và tên người gửi <span style="color: #dc2626;">*</span>
            </label>
            <input 
              type="text" 
              id="sender_name" 
              name="sender_name" 
              required 
              style="width: 100%; padding: 0.5rem 0.75rem; border: 1px solid #d1d5db; border-radius: 4px; box-sizing: border-box;"
              placeholder="Nguyễn Văn A"
            >
          </div>

          <div>
            <label for="student_code" style="display: block; font-weight: 500; font-size: 0.9rem; margin-bottom: 0.25rem;">
              Mã số Sinh viên (MSSV) <span style="color: #dc2626;">*</span>
            </label>
            <input 
              type="text" 
              id="student_code" 
              name="student_code" 
              required 
              style="width: 100%; padding: 0.5rem 0.75rem; border: 1px solid #d1d5db; border-radius: 4px; box-sizing: border-box;"
              placeholder="SV001"
            >
          </div>

          <div>
            <label for="email" style="display: block; font-weight: 500; font-size: 0.9rem; margin-bottom: 0.25rem;">
              Địa chỉ Email nhận phản hồi
            </label>
            <input 
              type="email" 
              id="email" 
              name="email" 
              style="width: 100%; padding: 0.5rem 0.75rem; border: 1px solid #d1d5db; border-radius: 4px; box-sizing: border-box;"
              placeholder="sinhvien@truong.edu.vn"
            >
          </div>

          <div>
            <label for="content" style="display: block; font-weight: 500; font-size: 0.9rem; margin-bottom: 0.25rem;">
              Nội dung thắc mắc / đề nghị hỗ trợ <span style="color: #dc2626;">*</span>
            </label>
            <textarea 
              id="content" 
              name="content" 
              rows="4" 
              required 
              style="width: 100%; padding: 0.5rem 0.75rem; border: 1px solid #d1d5db; border-radius: 4px; box-sizing: border-box;"
              placeholder="Mô tả chi tiết nội dung cần hỗ trợ (vd: thắc mắc số dư công nợ HK1, tiến độ xử lý hoàn tiền...)"
            ></textarea>
          </div>

          <button type="submit" class="btn btn-primary" style="margin-top: 0.5rem;">
            Gửi yêu cầu hỗ trợ
          </button>
        </form>
      </div>
    </div>

    <div style="margin-top: 2rem; text-align: center; color: #6b7280; font-size: 0.875rem;">
      <p>&copy; 2026 Hệ thống Quản lý Học phí Sinh viên. Bản quyền thuộc về Nhà trường.</p>
    </div>
  </div>

</body>
</html>
