<?php
session_start();

// Database connection settings - update with your credentials
$dbHost = 'localhost';
$dbName = 'arsimbo_db';
$dbUser = 'root';
$dbPass = '';

// Directory to save uploaded files
$uploadDir = __DIR__ . '/uploads/';

// Allowed mime types and extensions for uploads
$allowedMimeTypes = [
    'application/pdf',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'image/jpeg',
    'image/png',
    'image/gif',
    'video/mp4',
    'audio/mpeg',
    'application/zip',
    'application/x-rar-compressed',
    'application/vnd.ms-powerpoint',
    'application/vnd.openxmlformats-officedocument.presentationml.presentation',
];

// Max file size in bytes (10MB)
$maxFileSize = 10 * 1024 * 1024;

$message = '';
$error = '';

// Create uploads directory if not exist
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

// Connect to database using PDO
try {
    $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (PDOException $e) {
    die("Database connection failed: " . htmlspecialchars($e->getMessage()));
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['form_type']) && $_POST['form_type'] === 'upload') {
    // Sanitize inputs
    $nama = trim($_POST['nama'] ?? '');
    $kelas = trim($_POST['kelas'] ?? '');
    $mataPelajaran = trim($_POST['mataPelajaran'] ?? '');
    $mataPelajaranCustom = trim($_POST['mataPelajaranCustom'] ?? '');
    $jenisKarya = trim($_POST['jenisKarya'] ?? '');
    $jenisKaryaCustom = trim($_POST['jenisKaryaCustom'] ?? '');
    $judulKarya = trim($_POST['judulKarya'] ?? '');
    $deskripsi = trim($_POST['deskripsi'] ?? '');

    // Determine final values for optional "Other" fields
    if ($mataPelajaran === 'Lainnya') {
        $mataPelajaranFinal = $mataPelajaranCustom;
    } else {
        $mataPelajaranFinal = $mataPelajaran;
    }
    if ($jenisKarya === 'Lainnya') {
        $jenisKaryaFinal = $jenisKaryaCustom;
    } else {
        $jenisKaryaFinal = $jenisKarya;
    }

    // Basic validation
    if (empty($nama) || empty($kelas) || empty($mataPelajaranFinal) || empty($jenisKaryaFinal) || empty($judulKarya)) {
        $error = 'Mohon lengkapi semua field wajib.';
    } elseif (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        $error = 'Terjadi kesalahan saat mengunggah file.';
    } else {
        $file = $_FILES['file'];
        $fileSize = $file['size'];
        $fileTmpPath = $file['tmp_name'];
        $fileName = basename($file['name']);
        $fileMime = mime_content_type($fileTmpPath);

        if ($fileSize > $maxFileSize) {
            $error = 'Ukuran file melebihi 10MB.';
        } elseif (!in_array($fileMime, $allowedMimeTypes)) {
            $error = 'Tipe file tidak diperbolehkan.';
        } else {
            // Sanitize filename and generate unique name to save
            $safeFileName = preg_replace('/[^a-zA-Z0-9\-_\.]/', '_', $fileName);
            $uniqueFileName = uniqid() . '_' . $safeFileName;
            $destination = $uploadDir . $uniqueFileName;

            if (move_uploaded_file($fileTmpPath, $destination)) {
                // Insert data into database
                $stmt = $pdo->prepare("INSERT INTO karya_arsimbo 
                    (nama, kelas, mata_pelajaran, jenis_karya, judul_karya, deskripsi, file_name, file_path, uploaded_at)
                    VALUES (:nama, :kelas, :mata_pelajaran, :jenis_karya, :judul_karya, :deskripsi, :file_name, :file_path, NOW())");
                $stmt->execute([
                    ':nama' => $nama,
                    ':kelas' => $kelas,
                    ':mata_pelajaran' => $mataPelajaranFinal,
                    ':jenis_karya' => $jenisKaryaFinal,
                    ':judul_karya' => $judulKarya,
                    ':deskripsi' => $deskripsi,
                    ':file_name' => $fileName,
                    ':file_path' => 'uploads/' . $uniqueFileName,
                ]);
                $message = '✅ Karya berhasil diunggah! Terima kasih atas partisipasi Anda.';
                // Save user name to session to identify user for "Karya Saya"
                $_SESSION['user_nama'] = $nama;
            } else {
                $error = 'Gagal menyimpan file.';
            }
        }
    }
}

// Handle filter for Karya Saya
$userNama = $_SESSION['user_nama'] ?? '';

// Pagination and filter parameters for "Karya Saya"
$myWorksPage = max(1, intval($_GET['myworks_page'] ?? 1));
$myWorksPerPage = 10;
$myWorksOffset = ($myWorksPage - 1) * $myWorksPerPage;

// Fetch "Karya Saya" if userNama set
$myWorks = [];
$myWorksTotal = 0;
if ($userNama) {
    $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM karya_arsimbo WHERE nama = ?");
    $stmtCount->execute([$userNama]);
    $myWorksTotal = (int)$stmtCount->fetchColumn();

    $stmt = $pdo->prepare("SELECT * FROM karya_arsimbo WHERE nama = ? ORDER BY uploaded_at DESC LIMIT ? OFFSET ?");
    $stmt->bindValue(1, $userNama, PDO::PARAM_STR);
    $stmt->bindValue(2, $myWorksPerPage, PDO::PARAM_INT);
    $stmt->bindValue(3, $myWorksOffset, PDO::PARAM_INT);
    $stmt->execute();
    $myWorks = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Pagination and filtering for Galeri Karya
$galleryPage = max(1, intval($_GET['gallery_page'] ?? 1));
$galleryPerPage = 12;
$galleryOffset = ($galleryPage -1) * $galleryPerPage;

// Fetch total gallery count for pagination
$stmtGalleryCount = $pdo->query("SELECT COUNT(*) FROM karya_arsimbo");
$galleryTotal = (int)$stmtGalleryCount->fetchColumn();

// Fetch gallery page data
$stmtGallery = $pdo->prepare("SELECT * FROM karya_arsimbo ORDER BY uploaded_at DESC LIMIT ? OFFSET ?");
$stmtGallery->bindValue(1, $galleryPerPage, PDO::PARAM_INT);
$stmtGallery->bindValue(2, $galleryOffset, PDO::PARAM_INT);
$stmtGallery->execute();
$galleryWorks = $stmtGallery->fetchAll(PDO::FETCH_ASSOC);

// Helper function to escape output
function e($str) {
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}

// Helper function to format date (Indonesian format)
function formatDateIndo($dateStr) {
    $date = DateTime::createFromFormat('Y-m-d H:i:s', $dateStr);
    if (!$date) return $dateStr;
    setlocale(LC_TIME, 'id_ID.utf8', 'indonesian');
    return $date->format('d M Y H:i');
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Jejak Karya Arsimbo - Upload dan Galeri</title>

  <style>
    /* Start of your provided CSS from MultipleFiles/style.css */
    .nav-tabs button.nav-tab {
      cursor: pointer;
      padding: 8px 16px;
      background-color: #eee;
      border: 1px solid #ccc;
      border-bottom: none;
      margin-right: 5px;
      border-radius: 5px 5px 0 0;
      font-size: 14px;
    }
    .nav-tabs button.nav-tab.active {
      background-color: white;
      border-bottom: 1px solid white;
      font-weight: bold;
    }
    .section {
      display: none;
      padding: 20px;
      background: white;
      border: 1px solid #ccc;
      border-radius: 0 5px 5px 5px;
      margin-top: -1px;
    }
    .section.active {
      display: block;
    }
    /* Reset & Base */
    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
    }

    body {
      font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
      background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
      min-height: 100vh;
    }

    /* Navbar */
    .navbar {
      background: rgba(255, 255, 255, 0.95);
      backdrop-filter: blur(10px);
      padding: 15px 0;
      box-shadow: 0 2px 20px rgba(0, 0, 0, 0.1);
      position: sticky;
      top: 0;
      z-index: 1000;
    }

    .navbar .container {
      max-width: 1200px;
      margin: 0 auto;
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 0 20px;
    }

    .logo {
      font-size: 1.8em;
      font-weight: 700;
      color: #667eea;
    }

    .nav-tabs {
      display: flex;
      gap: 10px;
    }

    .nav-tab {
      padding: 12px 24px;
      background: transparent;
      border: 2px solid #667eea;
      color: #667eea;
      border-radius: 25px;
      cursor: pointer;
      transition: all 0.3s ease;
      font-weight: 600;
    }

    .nav-tab.active,
    .nav-tab:hover {
      background: #667eea;
      color: white;
    }

    /* Container */
    .main-container {
      max-width: 1200px;
      margin: 0 auto;
      padding: 20px;
    }

    .section {
      display: none;
      background: white;
      border-radius: 20px;
      box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
      overflow: hidden;
      margin-bottom: 20px;
    }

    .section.active {
      display: block;
    }

    .header {
      background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
      color: white;
      padding: 40px;
      text-align: center;
    }

    .header h1 {
      font-size: 2.5em;
      margin-bottom: 10px;
      font-weight: 700;
    }

    .header p {
      font-size: 1.1em;
      opacity: 0.9;
    }

    /* Form */
    .form-container, .gallery-container {
      padding: 40px;
    }

    .form-group {
      margin-bottom: 25px;
    }

    .form-group label {
      display: block;
      margin-bottom: 8px;
      font-weight: 600;
      color: #333;
      font-size: 1.1em;
    }

    .form-group input,
    .form-group select,
    .form-group textarea {
      width: 100%;
      padding: 15px;
      border: 2px solid #e1e5e9;
      border-radius: 12px;
      font-size: 16px;
      transition: all 0.3s ease;
      background: #f8f9fa;
    }

    .form-group input:focus,
    .form-group select:focus,
    .form-group textarea:focus {
      outline: none;
      border-color: #4facfe;
      background: white;
      box-shadow: 0 0 0 3px rgba(79, 172, 254, 0.1);
    }

    .form-row {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 20px;
    }

    /* File Upload */
    .file-upload {
      position: relative;
      display: block;
      padding: 20px;
      border: 3px dashed #4facfe;
      border-radius: 12px;
      text-align: center;
      background: #f8f9ff;
      cursor: pointer;
      transition: all 0.3s ease;
    }

    .file-upload:hover {
      background: #e6f3ff;
      border-color: #2196f3;
    }

    .file-upload input[type="file"] {
      position: absolute;
      left: -9999px;
    }

    .file-upload-icon {
      font-size: 3em;
      color: #4facfe;
      margin-bottom: 10px;
    }

    .file-upload-text {
      font-size: 1.1em;
      color: #666;
    }

    .file-info {
      margin-top: 10px;
      padding: 10px;
      background: #e8f5e8;
      border-radius: 8px;
      color: #2e7d2e;
      display: none;
    }

    .submit-btn {
      width: 100%;
      padding: 18px;
      background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
      color: white;
      border: none;
      border-radius: 12px;
      font-size: 1.2em;
      font-weight: 600;
      cursor: pointer;
      transition: all 0.3s ease;
      margin-top: 30px;
    }

    .submit-btn:hover {
      transform: translateY(-2px);
      box-shadow: 0 10px 20px rgba(102, 126, 234, 0.3);
    }

    .success-message {
      display: none;
      background: #d4edda;
      color: #155724;
      padding: 20px;
      border-radius: 12px;
      margin-bottom: 20px;
      border-left: 5px solid #28a745;
    }

    .hidden {
      display: none;
    }

    /* Gallery */
    .gallery-header {
      text-align: center;
      margin-bottom: 40px;
    }

    .gallery-header h2 {
      font-size: 2.2em;
      color: #333;
      margin-bottom: 15px;
    }

    .search-filter {
      display: flex;
      gap: 15px;
      margin-bottom: 30px;
      flex-wrap: wrap;
    }

    .search-filter input,
    .search-filter select {
      padding: 12px;
      border: 2px solid #e1e5e9;
      border-radius: 8px;
      font-size: 16px;
    }

    .search-filter input {
      flex: 1;
      min-width: 250px;
    }

    .karya-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
      gap: 25px;
    }

    .karya-card {
      background: white;
      border-radius: 15px;
      box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
      overflow: hidden;
      transition: transform 0.3s ease, box-shadow 0.3s ease;
      display: flex;
      flex-direction: column;
      height: 100%;
    }

    .karya-card:hover {
      transform: translateY(-5px);
      box-shadow: 0 15px 35px rgba(0, 0, 0, 0.15);
    }

    .karya-header {
      background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
      color: white;
      padding: 20px;
      font-weight: 700;
      font-size: 1.2em;
      user-select: text;
    }

    .karya-title {
      font-size: 1.3em;
      font-weight: 600;
      margin-bottom: 8px;
    }

    .karya-author {
      opacity: 0.9;
      font-size: 1em;
    }

    .karya-details {
      padding: 20px;
      color: #333;
      flex-grow: 1;
      user-select: text;
      display: flex;
      flex-direction: column;
    }

    .karya-info {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 15px;
      margin-bottom: 15px;
    }

    .info-item {
      display: flex;
      flex-direction: column;
    }

    .info-label {
      font-size: 0.9em;
      color: #666;
      margin-bottom: 5px;
      user-select: text;
    }

    .info-value {
      font-weight: 600;
      color: #333;
      user-select: text;
    }

    .karya-description {
      font-size: 0.95em;
      color: #555;
      margin-top: auto;
      user-select: text;
    }

    .karya-actions {
      display: flex;
      gap: 10px;
      margin-top: 15px;
    }

    .btn {
      padding: 10px 20px;
      border: none;
      border-radius: 8px;
      cursor: pointer;
      font-weight: 600;
      transition: all 0.3s ease;
      text-decoration: none;
      display: inline-block;
      text-align: center;
      user-select: none;
      font-size: 1em;
    }

    .btn-primary {
      background: #4facfe;
      color: white;
    }

    .btn-primary:hover {
      background: #2196f3;
    }

    .btn-danger {
      background: #ff4757;
      color: white;
    }

    .btn-danger:hover {
      background: #ff3838;
    }

    .empty-state {
      text-align: center;
      padding: 60px 20px;
      color: #666;
      font-size: 1.1em;
      user-select: text;
    }

    .empty-state-icon {
      font-size: 4em;
      margin-bottom: 20px;
      opacity: 0.5;
    }

    /* Pagination */
    .pagination {
      display: flex;
      justify-content: center;
      margin-top: 25px;
      gap: 10px;
      flex-wrap: wrap;
    }
    .pagination button {
      background: white;
      border: 1px solid #667eea;
      color: #667eea;
      font-size: 1em;
      padding: 8px 14px;
      border-radius: 8px;
      cursor: pointer;
      transition: background-color 0.25s ease, color 0.25s ease;
      user-select: none;
    }
    .pagination button:hover:not(:disabled) {
      background: #667eea;
      color: white;
    }
    .pagination button:disabled {
      opacity: 0.5;
      cursor: default;
    }

    /* Responsive */
    @media (max-width: 768px) {
      .nav-tabs {
          gap: 5px;
      }

      .nav-tab {
          padding: 10px 16px;
          font-size: 0.9em;
      }

      .form-row {
          grid-template-columns: 1fr;
      }

      .header h1 {
          font-size: 2em;
      }

      .main-container {
          padding: 10px;
      }

      .header,
      .form-container,
      .gallery-container {
          padding: 30px 20px;
      }

      .karya-grid {
          grid-template-columns: 1fr;
      }

      .search-filter {
          flex-direction: column;
      }

      .search-filter input {
          min-width: auto;
      }

      .stats {
          gap: 20px;
      }
    }
  </style>
</head>
<body>
  <nav class="navbar" role="navigation" aria-label="Primary Navigation">
    <div class="container">
      <div class="logo" aria-label="Jejak Karya Arsimbo Logo">Jejak Karya Arsimbo</div>
      <div class="nav-tabs" role="tablist" aria-label="Site Sections">
        <button class="nav-tab active" role="tab" aria-selected="true" aria-controls="upload" id="tab-upload" type="button" onclick="showSection('upload', this)">Upload Karya</button>
        <button class="nav-tab" role="tab" aria-selected="false" aria-controls="myworks" id="tab-myworks" type="button" onclick="showSection('myworks', this)">Karya Saya</button>
        <button class="nav-tab" role="tab" aria-selected="false" aria-controls="gallery" id="tab-gallery" type="button" onclick="showSection('gallery', this)">Galeri Karya</button>
      </div>
    </div>
  </nav>

  <main class="main-container" role="main" tabindex="-1">
    <!-- Upload Section -->
    <section id="upload" class="section active" role="tabpanel" tabindex="0" aria-labelledby="tab-upload" aria-label="Upload karya">
      <header class="header" role="banner">
        <h1>Upload Karya</h1>
        <p>Bagikan karya terbaikmu dengan teman-teman!</p>
      </header>

      <?php if($message): ?>
      <div class="success-message" role="alert" aria-live="polite"><?= e($message) ?></div>
      <?php elseif($error): ?>
      <div class="success-message" style="background:#fee2e2; color:#991b1b; border-color:#ef4444;" role="alert" aria-live="assertive"><?= e($error) ?></div>
      <?php endif; ?>

      <form id="karyaForm" method="post" enctype="multipart/form-data" novalidate class="form-container">
        <input type="hidden" name="form_type" value="upload" />
        <div class="form-group">
          <label for="nama">Nama Lengkap <sup>*</sup></label>
          <input type="text" id="nama" name="nama" required value="<?= e($_POST['nama'] ?? $userNama) ?>" />
        </div>

        <div class="form-row">
          <div class="form-group">
            <label for="kelas">Kelas <sup>*</sup></label>
            <select id="kelas" name="kelas" required>
              <option value="">Pilih Kelas</option>
              <?php
                $kelasOptions = ['7A','7B','7C','7D','7E','7F','7G','7H','7I','7J',
                                 '8A','8B','8C','8D','8E','8F','8G','8H','8I','8J',
                                 '9A','9B','9C','9D','9E','9F','9G','9H','9I','9J'];
                $selectedKelas = $_POST['kelas'] ?? '';
                foreach ($kelasOptions as $opt) {
                    echo '<option value="'.e($opt).'" '.($selectedKelas === $opt ? 'selected' : '').'>'.e($opt).'</option>';
                }
              ?>
            </select>
          </div>

          <div class="form-group">
            <label for="mataPelajaran">Mata Pelajaran <sup>*</sup></label>
            <select id="mataPelajaran" name="mataPelajaran" required aria-controls="mataPelajaranLainnya" aria-expanded="false" onchange="toggleSubjectOther()">
              <option value="">Pilih Mata Pelajaran</option>
              <?php
                $mapelOptions = ['Bahasa Indonesia','Matematika','IPA (Sains)','Bahasa Inggris','Bahasa Jawa','Pendidikan Agama','Pendidikan Pancasila','IPS','Seni Budaya','Informatika','PJOK','Lainnya'];
                $selectedMapel = $_POST['mataPelajaran'] ?? '';
                foreach ($mapelOptions as $opt) {
                    echo '<option value="'.e($opt).'" '.($selectedMapel === $opt ? 'selected' : '').'>'.e($opt).'</option>';
                }
              ?>
            </select>
          </div>
        </div>

        <div class="form-group hidden" id="mataPelajaranLainnya">
          <label for="mataPelajaranCustom">Nama Mata Pelajaran Lainnya <sup>*</sup></label>
          <input type="text" id="mataPelajaranCustom" name="mataPelajaranCustom" placeholder="Masukkan nama mata pelajaran" value="<?= e($_POST['mataPelajaranCustom'] ?? '') ?>" />
        </div>

        <div class="form-group">
          <label for="jenisKarya">Jenis Karya <sup>*</sup></label>
          <select id="jenisKarya" name="jenisKarya" required aria-controls="jenisKaryaLainnya" aria-expanded="false" onchange="toggleWorkTypeOther()">
            <option value="">Pilih Jenis Karya</option>
            <?php
              $workTypeOptions = ['Proyek','Portofolio','Karya Seni','Karya Tulis','Lainnya'];
              $selectedWorkType = $_POST['jenisKarya'] ?? '';
              foreach ($workTypeOptions as $opt) {
                  echo '<option value="'.e($opt).'" '.($selectedWorkType === $opt ? 'selected' : '').'>'.e($opt).'</option>';
              }
            ?>
          </select>
        </div>

        <div class="form-group hidden" id="jenisKaryaLainnya">
          <label for="jenisKaryaCustom">Nama Jenis Karya Lainnya <sup>*</sup></label>
          <input type="text" id="jenisKaryaCustom" name="jenisKaryaCustom" placeholder="Masukkan jenis karya" value="<?= e($_POST['jenisKaryaCustom'] ?? '') ?>" />
        </div>

        <div class="form-group">
          <label for="judulKarya">Judul Karya <sup>*</sup></label>
          <input type="text" id="judulKarya" name="judulKarya" required placeholder="Masukkan judul karya Anda" value="<?= e($_POST['judulKarya'] ?? '') ?>" />
        </div>

        <div class="form-group">
          <label for="deskripsi">Deskripsi Karya</label>
          <textarea id="deskripsi" name="deskripsi" rows="4" placeholder="Ceritakan tentang karya Anda (opsional)"><?= e($_POST['deskripsi'] ?? '') ?></textarea>
        </div>

        <div class="form-group">
          <label for="file" class="file-upload" tabindex="0" aria-label="Upload file karya">
            <div class="file-upload-icon">📁</div>
            <div class="file-upload-text">
              <strong>Klik untuk memilih file</strong><br />
              atau seret file ke sini<br />
              <small>Maksimal 10MB • PDF, DOC, DOCX, JPG, PNG, MP4, dll</small>
            </div>
            <input type="file" id="file" name="file" required accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.gif,.mp4,.mp3,.zip,.rar,.ppt,.pptx" />
          </label>
          <div class="file-info" id="fileInfo" aria-live="polite"></div>
        </div>

        <button type="submit" class="submit-btn">🚀 Unggah Karya</button>
      </form>
    </section>

    <!-- Karya Saya Section -->
    <section id="myworks" class="section" role="tabpanel" tabindex="0" aria-labelledby="tab-myworks" aria-label="Karya saya">
      <header class="header">
        <h1>Karya Saya</h1>
        <p>Kelola dan lihat semua karya yang telah Anda unggah</p>
      </header>
      <?php if (!$userNama): ?>
        <p class="empty-state">Anda belum mengunggah karya apapun. Silakan unggah di bagian <strong>Upload Karya</strong>.</p>
      <?php else: ?>
        <?php if (empty($myWorks)): ?>
          <p class="empty-state">Anda belum mengunggah karya apapun.</p>
        <?php else: ?>
          <div class="karya-grid" role="list" aria-label="Daftar karya saya">
            <?php foreach ($myWorks as $work): ?>
              <article class="karya-card" role="listitem" tabindex="0" aria-labelledby="title-<?= e($work['id']) ?>">
                <div class="karya-header">
                  <div id="title-<?= e($work['id']) ?>" class="karya-title"><?= e($work['judul_karya']) ?></div>
                  <div class="karya-author"><?= e($work['nama']) ?> — <?= e($work['kelas']) ?></div>
                </div>
                <div class="karya-details">
                  <div class="karya-info">
                    <div class="info-item">
                      <div class="info-label">Mata Pelajaran</div>
                      <div class="info-value"><?= e($work['mata_pelajaran']) ?></div>
                    </div>
                    <div class="info-item">
                      <div class="info-label">Jenis Karya</div>
                      <div class="info-value"><?= e($work['jenis_karya']) ?></div>
                    </div>
                  </div>
                  <div class="karya-description"><?= nl2br(e(substr($work['deskripsi'], 0, 150))) ?><?= strlen($work['deskripsi']) > 150 ? '...' : '' ?></div>
                  <div class="karya-info" style="margin-top:0.5rem; color:#666; font-size:0.9em; font-style: italic;">
                    Diunggah pada: <?= e(formatDateIndo($work['uploaded_at'])) ?>
                  </div>
                  <div class="karya-actions">
                    <a href="<?= e($work['file_path']) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-primary" download aria-label="Unduh karya <?= e($work['judul_karya']) ?>">Unduh</a>
                  </div>
                </div>
              </article>
            <?php endforeach; ?>
          </div>
          <?php
            $myWorksPages = ceil($myWorksTotal / $myWorksPerPage);
            if ($myWorksPages > 1):
          ?>
          <nav class="pagination" role="navigation" aria-label="Pagination Karya Saya">
            <form id="myworksPageForm" method="get" style="display:inline;">
              <input type="hidden" name="tab" value="myworks">
              <button type="submit" name="myworks_page" value="<?= max(1, $myWorksPage - 1) ?>" <?= $myWorksPage <= 1 ? 'disabled' : '' ?> aria-label="Halaman sebelumnya">‹ Sebelumnya</button>
              <?php for($p=1; $p <= $myWorksPages; $p++): ?>
                <button type="submit" name="myworks_page" value="<?= $p ?>" <?= $p === $myWorksPage ? 'disabled aria-current="page"' : '' ?>><?= $p ?></button>
              <?php endfor; ?>
              <button type="submit" name="myworks_page" value="<?= min($myWorksPages, $myWorksPage + 1) ?>" <?= $myWorksPage >= $myWorksPages ? 'disabled' : '' ?> aria-label="Halaman berikutnya">Berikutnya ›</button>
            </form>
          </nav>
          <?php endif; ?>
        <?php endif; ?>
      <?php endif; ?>
    </section>

    <!-- Galeri Karya Section -->
    <section id="gallery" class="section" role="tabpanel" tabindex="0" aria-labelledby="tab-gallery" aria-label="Galeri karya">
      <header class="header">
        <h1>Galeri Karya</h1>
        <p>Jelajahi karya-karya menakjubkan dari seluruh siswa</p>
      </header>

      <div class="search-filter" role="search">
        <input type="search" id="searchInput" placeholder="Cari judul, nama, atau mata pelajaran..." aria-label="Cari karya dalam galeri" />
      </div>

      <?php if (empty($galleryWorks)): ?>
        <p class="empty-state">Belum ada karya yang diunggah untuk saat ini.</p>
      <?php else: ?>
        <div class="karya-grid" id="galleryGrid" role="list" aria-live="polite" aria-atomic="true">
        <?php foreach ($galleryWorks as $work): ?>
          <article class="karya-card" role="listitem" tabindex="0" aria-labelledby="gallery-title-<?= e($work['id']) ?>">
            <div class="karya-header">
              <div id="gallery-title-<?= e($work['id']) ?>" class="karya-title"><?= e($work['judul_karya']) ?></div>
              <div class="karya-author"><?= e($work['nama']) ?> — <?= e($work['kelas']) ?></div>
            </div>
            <div class="karya-details">
              <div class="karya-info">
                <div class="info-item">
                  <div class="info-label">Mata Pelajaran</div>
                  <div class="info-value"><?= e($work['mata_pelajaran']) ?></div>
                </div>
                <div class="info-item">
                  <div class="info-label">Jenis Karya</div>
                  <div class="info-value"><?= e($work['jenis_karya']) ?></div>
                </div>
              </div>
              <div class="karya-description"><?= nl2br(e(substr($work['deskripsi'], 0, 150))) ?><?= strlen($work['deskripsi']) > 150 ? '...' : '' ?></div>
              <div class="karya-info" style="margin-top:0.5rem; color:#666; font-size:0.9em; font-style: italic;">
                Diunggah pada: <?= e(formatDateIndo($work['uploaded_at'])) ?>
              </div>
              <div class="karya-actions">
                <a href="<?= e($work['file_path']) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-primary" download aria-label="Unduh karya <?= e($work['judul_karya']) ?>">Unduh</a>
              </div>
            </div>
          </article>
        <?php endforeach; ?>
        </div>

        <?php
          $galleryPages = ceil($galleryTotal / $galleryPerPage);
          if ($galleryPages > 1):
        ?>
        <nav class="pagination" role="navigation" aria-label="Pagination Galeri Karya">
          <form id="galleryPageForm" method="get" style="display:inline;">
            <input type="hidden" name="tab" value="gallery">
            <button type="submit" name="gallery_page" value="<?= max(1, $galleryPage - 1) ?>" <?= $galleryPage <= 1 ? 'disabled' : '' ?> aria-label="Halaman sebelumnya">‹ Sebelumnya</button>
            <?php for($p=1; $p <= $galleryPages; $p++): ?>
              <button type="submit" name="gallery_page" value="<?= $p ?>" <?= $p === $galleryPage ? 'disabled aria-current="page"' : '' ?>><?= $p ?></button>
            <?php endfor; ?>
            <button type="submit" name="gallery_page" value="<?= min($galleryPages, $galleryPage + 1) ?>" <?= $galleryPage >= $galleryPages ? 'disabled' : '' ?> aria-label="Halaman berikutnya">Berikutnya ›</button>
          </form>
        </nav>
        <?php endif; ?>
      <?php endif; ?>
    </section>
  </main>

  <script>
    // Tab switching with ARIA and keyboard support
    function showSection(sectionId, btn) {
      const sections = document.querySelectorAll('.section');
      const tabs = document.querySelectorAll('.nav-tab');

      sections.forEach(s => {
        s.classList.remove('active');
        s.setAttribute('aria-hidden', 'true');
        s.setAttribute('tabindex', '-1');
      });

      tabs.forEach(t => {
        t.classList.remove('active');
        t.setAttribute('aria-selected', 'false');
        t.setAttribute('tabindex', '-1');
      });

      const section = document.getElementById(sectionId);
      section.classList.add('active');
      section.setAttribute('aria-hidden', 'false');
      section.setAttribute('tabindex', '0');
      section.focus();

      if(btn) {
        btn.classList.add('active');
        btn.setAttribute('aria-selected', 'true');
        btn.setAttribute('tabindex', '0');
        btn.focus();
      }

      // Update URL query param "tab"
      if (history.pushState) {
        const newurl = new URL(window.location);
        newurl.searchParams.set('tab', sectionId);
        history.pushState(null, '', newurl);
      }
    }

    // Show/hide custom subject input
    function toggleSubjectOther() {
      const select = document.getElementById('mataPelajaran');
      const other = document.getElementById('mataPelajaranLainnya');
      if(select.value === 'Lainnya') {
        other.classList.remove('hidden');
        select.setAttribute('aria-expanded', 'true');
        document.getElementById('mataPelajaranCustom').setAttribute('required', 'required');
      } else {
        other.classList.add('hidden');
        select.setAttribute('aria-expanded', 'false');
        document.getElementById('mataPelajaranCustom').removeAttribute('required');
      }
    }

    // Show/hide custom work type input
    function toggleWorkTypeOther() {
      const select = document.getElementById('jenisKarya');
      const other = document.getElementById('jenisKaryaLainnya');
      if(select.value === 'Lainnya') {
        other.classList.remove('hidden');
        select.setAttribute('aria-expanded', 'true');
        document.getElementById('jenisKaryaCustom').setAttribute('required', 'required');
      } else {
        other.classList.add('hidden');
        select.setAttribute('aria-expanded', 'false');
        document.getElementById('jenisKaryaCustom').removeAttribute('required');
      }
    }

    // Initialize toggles on page load (to handle form value persistence)
    document.addEventListener('DOMContentLoaded', function () {
      toggleSubjectOther();
      toggleWorkTypeOther();

      // File input info update (upload tab)
      const fileInput = document.getElementById('file');
      const fileInfo = document.getElementById('fileInfo');
      fileInput.addEventListener('change', () => {
        if (fileInput.files.length > 0) {
          const sizeInMB = (fileInput.files[0].size / (1024 * 1024)).toFixed(2);
          fileInfo.style.display = 'block';
          fileInfo.textContent = `File terpilih: ${fileInput.files[0].name} (${sizeInMB} MB)`;
        } else {
          fileInfo.style.display = 'none';
          fileInfo.textContent = '';
        }
      });

      // Enable keyboard navigation for tabs (left/right arrows)
      const tabs = document.querySelectorAll('.nav-tab');
      tabs.forEach((tab, idx) => {
        tab.addEventListener('keydown', e => {
          if (e.key === 'ArrowRight') {
            e.preventDefault();
            const next = tabs[(idx + 1) % tabs.length];
            next.focus();
            next.click();
          } else if (e.key === 'ArrowLeft') {
            e.preventDefault();
            const prev = tabs[(idx - 1 + tabs.length) % tabs.length];
            prev.focus();
            prev.click();
          }
        });
      });

      // Restore tab from URL query param
      const urlParams = new URLSearchParams(window.location.search);
      const tabParam = urlParams.get('tab');
      if (tabParam) {
        const activeTabBtn = document.getElementById('tab-' + tabParam);
        if (activeTabBtn) {
          activeTabBtn.click();
        }
      }

      // Filtering for Gallery Works (client-side)
      const searchInput = document.getElementById('searchInput');
      const galleryGrid = document.getElementById('galleryGrid');
      if (searchInput && galleryGrid) {
        searchInput.addEventListener('input', () => {
          const filter = searchInput.value.toLowerCase();
          const cards = galleryGrid.querySelectorAll('.karya-card');
          let visibleCount = 0;
          cards.forEach(card => {
            const title = card.querySelector('.karya-title')?.textContent.toLowerCase() || '';
            const author = card.querySelector('.karya-author')?.textContent.toLowerCase() || '';
            const subject = card.querySelector('.info-value')?.textContent.toLowerCase() || '';
            const combinedText = title + ' ' + author + ' ' + subject;
            if (combinedText.includes(filter)) {
              card.style.display = '';
              visibleCount++;
            } else {
              card.style.display = 'none';
            }
          });
          // If no visible items, show empty state message
          let emptyState = galleryGrid.nextElementSibling;
          if(!emptyState || !emptyState.classList.contains('empty-state')) {
            emptyState = document.createElement('p');
            emptyState.className = 'empty-state';
            emptyState.textContent = 'Tidak ada karya yang cocok dengan pencarian.';
            galleryGrid.parentNode.insertBefore(emptyState, galleryGrid.nextSibling);
          }
          emptyState.style.display = visibleCount === 0 ? 'block' : 'none';
        });
      }
    });
  </script>

  <!-- Instruction for DB table creation in comments -->
  <!--
    Database table schema for 'karya_arsimbo':
    CREATE TABLE karya_arsimbo (
      id INT AUTO_INCREMENT PRIMARY KEY,
      nama VARCHAR(255) NOT NULL,
      kelas VARCHAR(10) NOT NULL,
      mata_pelajaran VARCHAR(255) NOT NULL,
      jenis_karya VARCHAR(255) NOT NULL,
      judul_karya VARCHAR(255) NOT NULL,
      deskripsi TEXT,
      file_name VARCHAR(255) NOT NULL,
      file_path VARCHAR(255) NOT NULL,
      uploaded_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
  -->

</body>
</html>

