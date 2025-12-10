<?php
// index.php
// Aplikasi Perpustakaan - Full PHP backend (single-file)

// --------------------
// Config & Utilities
// --------------------
$dataDir = __DIR__ . '/data';
if (!is_dir($dataDir)) mkdir($dataDir, 0755, true);

$booksFile   = $dataDir . '/books.json';
$membersFile = $dataDir . '/members.json';
$loansFile   = $dataDir . '/loans.json';
$logFile     = $dataDir . '/log.txt';

function loadJson($file) {
    if (!file_exists($file)) return [];
    $txt = file_get_contents($file);
    $arr = json_decode($txt, true);
    return is_array($arr) ? $arr : [];
}
function saveJson($file, $data) {
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}
function logWrite($msg) {
    global $logFile;
    $line = "[".date('Y-m-d H:i:s')."] ".$msg.PHP_EOL;
    file_put_contents($logFile, $line, FILE_APPEND);
}

// Debug mode toggle via ?debug=1
$debug = isset($_GET['debug']) && $_GET['debug'] == '1';

// Load current data
$books = loadJson($booksFile);
$members = loadJson($membersFile);
$loans = loadJson($loansFile);

// Messages / errors
$messages = [];
$errors = [];

// --------------------
// Handle POST actions
// --------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ---- ADD BOOK ----
    if ($action === 'add_book') {
        $judul = trim($_POST['judul'] ?? '');
        $kategori = trim($_POST['kategori'] ?? '');
        if ($judul === '') $errors[] = "Judul buku wajib diisi.";
        if ($kategori === '') $errors[] = "Kategori buku wajib dipilih.";
        if (empty($errors)) {
            $judul_upper = mb_strtoupper($judul, 'UTF-8');
            $slug = substr(preg_replace('/[^a-z0-9]+/i','-', strtolower($judul)),0,40);
            $book = [
                'id' => count($books) + 1,
                'judul' => $judul,
                'judul_upper' => $judul_upper,
                'slug' => $slug,
                'kategori' => $kategori,
                'status' => 'tersedia',
                'created_at' => date('c')
            ];
            $books[] = $book;
            saveJson($booksFile, $books);
            logWrite("Tambah Buku: {$judul} (kategori: {$kategori})");
            $messages[] = "Buku \"$judul\" berhasil ditambahkan.";
        }
    }

    // ---- EDIT BOOK ----
    if ($action === 'edit_book') {
        $bookId = intval($_POST['book_id'] ?? 0);
        $judul = trim($_POST['judul'] ?? '');
        $kategori = trim($_POST['kategori'] ?? '');
        if ($bookId && $judul && $kategori) {
            foreach ($books as &$b) {
                if ($b['id']==$bookId) {
                    $b['judul'] = $judul;
                    $b['judul_upper'] = mb_strtoupper($judul,'UTF-8');
                    $b['slug'] = substr(preg_replace('/[^a-z0-9]+/i','-', strtolower($judul)),0,40);
                    $b['kategori'] = $kategori;
                    logWrite("Edit Buku: {$bookId} - {$judul}");
                    $messages[] = "Buku berhasil diperbarui.";
                    break;
                }
            } unset($b);
            saveJson($booksFile, $books);
        } else {
            $errors[] = "Judul & kategori wajib diisi.";
        }
    }

    // ---- DELETE BOOK ----
    if ($action === 'delete_book') {
        $bookId = intval($_POST['book_id'] ?? 0);
        foreach ($books as $i=>$b) {
            if ($b['id']==$bookId && $b['status']=='tersedia') {
                array_splice($books,$i,1);
                saveJson($booksFile,$books);
                logWrite("Hapus Buku: {$bookId} - {$b['judul']}");
                $messages[] = "Buku berhasil dihapus.";
                break;
            }
        }
    }

    // ---- ADD MEMBER ----
    if ($action === 'add_member') {
        $nama = trim($_POST['nama'] ?? '');
        $email = trim($_POST['email'] ?? '');
        if ($nama === '') $errors[] = "Nama anggota wajib diisi.";
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "Format email tidak valid.";
        if (empty($errors)) {
            $member = [
                'id' => count($members) + 1,
                'nama' => $nama,
                'email' => $email,
                'joined_at' => date('c')
            ];
            $members[] = $member;
            saveJson($membersFile, $members);
            logWrite("Tambah Anggota: {$nama}" . ($email ? " <{$email}>" : ''));
            $messages[] = "Anggota \"$nama\" berhasil ditambahkan.";
        }
    }

    // ---- BORROW BOOK ----
    if ($action === 'borrow') {
        $bookId = intval($_POST['book_id'] ?? 0);
        $memberId = intval($_POST['member_id'] ?? 0);
        if (!$bookId) $errors[] = "Pilih buku yang akan dipinjam.";
        if (!$memberId) $errors[] = "Pilih anggota peminjam.";
        $book = null; foreach ($books as $b) if ($b['id']==$bookId) $book = $b;
        $member = null; foreach ($members as $m) if ($m['id']==$memberId) $member = $m;
        if (!$book) $errors[] = "Buku tidak ditemukan.";
        if (!$member) $errors[] = "Anggota tidak ditemukan.";
        if ($book && $book['status'] !== 'tersedia') $errors[] = "Buku tidak tersedia (status: {$book['status']}).";

        if (empty($errors)) {
            $tglPinjam = new DateTime();
            $deadline = clone $tglPinjam;
            $deadline->modify('+3 days');
            $loan = [
                'id' => count($loans) + 1,
                'bookId' => $bookId,
                'memberId' => $memberId,
                'bookTitle' => $book['judul'],
                'memberName' => $member['nama'],
                'pinjam_at' => $tglPinjam->format(DateTime::ATOM),
                'deadline_at' => $deadline->format(DateTime::ATOM),
                'kembali_at' => null,
                'status' => 'dipinjam'
            ];
            $loans[] = $loan;
            foreach ($books as &$b) if ($b['id'] == $bookId) { $b['status'] = 'dipinjam'; break; } unset($b);
            saveJson($loansFile, $loans);
            saveJson($booksFile, $books);
            logWrite("Pinjam: Buku[{$bookId}] oleh Member[{$memberId}]");
            $messages[] = "Buku \"{$book['judul']}\" dipinjam oleh {$member['nama']}. Deadline: ".$deadline->format('Y-m-d');
        }
    }

    // ---- RETURN BOOK ----
    if ($action === 'return') {
        $loanId = intval($_POST['loan_id'] ?? 0);
        if (!$loanId) $errors[] = "Pilih peminjaman untuk dikembalikan.";
        $loanIndex = null;
        foreach ($loans as $i => $L) if ($L['id']==$loanId) { $loanIndex = $i; break; }
        if ($loanIndex === null) $errors[] = "Data peminjaman tidak ditemukan.";

        if (empty($errors)) {
            $now = new DateTime();
            $loans[$loanIndex]['kembali_at'] = $now->format(DateTime::ATOM);
            $isLate = (new DateTime($loans[$loanIndex]['deadline_at']) < $now);
            $loans[$loanIndex]['status'] = $isLate ? 'telat' : 'kembali';
            $bookId = $loans[$loanIndex]['bookId'];
            foreach ($books as &$b) if ($b['id']==$bookId) { $b['status'] = 'tersedia'; break; } unset($b);
            saveJson($loansFile, $loans);
            saveJson($booksFile, $books);
            logWrite("Kembalikan: Loan[{$loanId}] - Buku[{$bookId}] oleh Member[{$loans[$loanIndex]['memberId']}] - ".($isLate?'TELAT':'ON TIME'));
            $messages[] = "Pengembalian tercatat. Status: " . ($isLate ? 'TELAT' : 'Tepat waktu');
        }
    }

    // ---- CLEAR LOGS ----
    if ($action === 'clear_logs') {
        file_put_contents($logFile, "");
        $messages[] = "Log dibersihkan.";
    }
}

// reload after modifications
$books = loadJson($booksFile);
$members = loadJson($membersFile);
$loans = loadJson($loansFile);

// Helper for display
function shortDate($iso) {
    if (!$iso) return '-';
    $d = new DateTime($iso);
    return $d->format('Y-m-d');
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Perpustakaan (PHP Backend) - Pastel</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
/* ========== STYLING SAMA SEPERTI SEBELUMNYA ========== */
body{font-family:Poppins,system-ui,Segoe UI,Arial;background:linear-gradient(150deg,#ffe8ff,#e8e7ff,#d8f1ff);margin:0;color:#222}
.container{max-width:1100px;margin:18px auto;padding:12px}
nav{display:flex;gap:8px;padding:10px;background:linear-gradient(90deg,#ffd7f5,#d9a7ff,#aecbff);border-radius:8px}
nav form{margin:0}
nav button, nav input[type=submit]{background:white;border:0;padding:8px 12px;border-radius:8px;cursor:pointer}
.header-row{display:flex;justify-content:space-between;align-items:center;margin:12px 0}
.card{background:rgba(255,255,255,0.9);padding:14px;border-radius:10px;margin-bottom:14px;box-shadow:0 6px 18px rgba(0,0,0,0.06)}
.row{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
input, select{padding:8px;border-radius:8px;border:1px solid #d6c6ff}
.btn{background:linear-gradient(90deg,#c79bff,#92b5ff);border:0;padding:8px 12px;border-radius:8px;color:white;cursor:pointer}
.btn-red{background:linear-gradient(90deg,#ff7b9c,#ff93b5)}
.btn-purple{background:linear-gradient(90deg,#b38bff,#c0a0ff)}
table{width:100%;border-collapse:collapse;border:2px solid #d7caff;background:white;border-radius:8px;overflow:hidden}
th,td{padding:10px;border:1px solid #e1d5ff;text-align:justify}
th{text-align:center;background:#f3eaff}
.badge{padding:4px 10px;border-radius:12px;color:white;font-weight:700}
.bg-green{background:#7aeab1}
.bg-red{background:#ff7b9c}
.bg-purple{background:#b38bff}
.msg{padding:8px;background:#e7ffef;border-radius:8px;margin-bottom:10px;color:#0b5b2f}
.err{padding:8px;background:#ffe7ec;border-radius:8px;margin-bottom:10px;color:#7a0011}
.small{font-size:13px;color:#666;margin-top:6px}
.code{background:#fafafa;padding:10px;border-radius:6px;border:1px dashed #ddd;white-space:pre-wrap;font-family:mono}
.debug{background:#fff6d6;padding:10px;border-radius:6px;border:1px solid #ffd27a;color:#4a3b00}

/* Modal */
.modal-bg {display: none; position: fixed; top:0; left:0; width:100%; height:100%; background: rgba(0,0,0,0.4); z-index:1000; justify-content:center; align-items:center;}
.modal {background: #fff; padding:20px; border-radius:12px; max-width:500px; width:90%; box-shadow:0 6px 20px rgba(0,0,0,0.15); position:relative;}
.modal h3 {margin-top:0; margin-bottom:10px;}
.modal .close {position:absolute; top:10px; right:10px; cursor:pointer; font-weight:bold;}
.modal input, .modal select {width:100%; margin-bottom:10px; padding:8px; border-radius:8px; border:1px solid #d6c6ff;}
.modal button {width:100%; padding:10px; border-radius:8px; border:none; cursor:pointer; background:linear-gradient(90deg,#c79bff,#92b5ff); color:white; font-weight:600;}
</style>
</head>
<body>
<div class="container">
    <nav>
        <form method="get" style="display:inline"><button type="submit" name="page" value="books">Buku</button></form>
        <form method="get"><button type="submit" name="page" value="members">Anggota</button></form>
        <form method="get"><button type="submit" name="page" value="loans">Peminjaman</button></form>
        <form method="get"><button type="submit" name="page" value="returns">Pengembalian</button></form>
        <div style="flex:1"></div>
        <form method="get" style="margin-left:8px">
            <input type="hidden" name="debug" value="<?php echo $debug? '0':'1'; ?>">
            <button type="submit"><?php echo $debug? 'Debug: ON':'Debug: OFF'; ?></button>
        </form>
    </nav>

    <?php if (!empty($messages)): foreach ($messages as $m): ?>
        <div class="msg"><?php echo htmlspecialchars($m); ?></div>
    <?php endforeach; endif; ?>

    <?php if (!empty($errors)): foreach ($errors as $e): ?>
        <div class="err"><?php echo htmlspecialchars($e); ?></div>
    <?php endforeach; endif; ?>

<?php
$page = $_GET['page'] ?? 'books';
if (!in_array($page, ['books','members','loans','returns'])) $page='books';
?>

<!-- ==================== HALAMAN BUKU ==================== -->
<?php if ($page==='books'): ?>
<div class="card">
    <h2>Tambah Buku</h2>
    <form method="post" class="row">
        <input type="hidden" name="action" value="add_book">
        <input name="judul" placeholder="Judul buku" required>
        <select name="kategori" required>
            <option value="">-- Pilih kategori --</option>
            <option>Fiksi</option><option>Non-Fiksi</option><option>Komik</option>
            <option>Novel</option><option>Pendidikan</option><option>Referensi</option>
        </select>
        <button class="btn" type="submit">Tambah</button>
    </form>
    <p class="small">Judul akan disimpan juga sebagai UPPERCASE dan slug.</p>
</div>

<div class="card">
    <h2>Daftar Buku</h2>
    <table>
        <thead><tr><th>ID</th><th>Judul</th><th>Kategori</th><th>Status & Aksi</th></tr></thead>
        <tbody>
            <?php if (!empty($books)): ?>
                <?php foreach ($books as $b): ?>
                <tr>
                    <td style="vertical-align:middle;"><?php echo $b['id']; ?></td>
                    <td><?php echo htmlspecialchars($b['judul']); ?>
                        <div class="small">UPPER: <?php echo htmlspecialchars($b['judul_upper']); ?> — slug: <?php echo htmlspecialchars($b['slug']); ?></div>
                    </td>
                    <td style="vertical-align:middle;"><?php echo htmlspecialchars($b['kategori']); ?></td>
                    <td style="vertical-align:middle;">
                        <span class="badge <?php echo ($b['status']=='tersedia'?'bg-green':'bg-red'); ?>"><?php echo $b['status']; ?></span>
                        <div class="row" style="margin-top:6px; gap:4px;">
                            <button class="btn btn-purple" type="button" onclick="openModal('<?php echo $b['id']; ?>','<?php echo htmlspecialchars($b['judul']); ?>','<?php echo $b['kategori']; ?>')">Edit</button>
                            <?php if($b['status']=='tersedia'): ?>
                            <form method="post" style="display:inline-flex;">
                                <input type="hidden" name="action" value="delete_book">
                                <input type="hidden" name="book_id" value="<?php echo $b['id']; ?>">
                                <button class="btn btn-red" type="submit" onclick="return confirm('Yakin hapus buku ini?')">Hapus</button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="4" style="text-align:center">Belum ada data buku.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Modal Edit Buku -->
<div class="modal-bg" id="modalBg">
    <div class="modal">
        <span class="close" onclick="closeModal()">×</span>
        <h3>Edit Buku</h3>
        <form method="post">
            <input type="hidden" name="action" value="edit_book">
            <input type="hidden" name="book_id" id="modalBookId">
            <input type="text" name="judul" id="modalJudul" placeholder="Judul buku" required>
            <select name="kategori" id="modalKategori" required>
                <option>Fiksi</option><option>Non-Fiksi</option><option>Komik</option>
                <option>Novel</option><option>Pendidikan</option><option>Referensi</option>
            </select>
            <button type="submit">Simpan Perubahan</button>
        </form>
    </div>
</div>

<script>
function openModal(id, judul, kategori) {
    document.getElementById('modalBookId').value = id;
    document.getElementById('modalJudul').value = judul;
    document.getElementById('modalKategori').value = kategori;
    document.getElementById('modalBg').style.display = 'flex';
}
function closeModal() {
    document.getElementById('modalBg').style.display = 'none';
}
</script>
<?php endif; ?>

<!-- ==================== HALAMAN ANGGOTA ==================== -->
<?php if ($page==='members'): ?>
<div class="card">
    <h2>Tambah Anggota</h2>
    <form method="post" class="row">
        <input type="hidden" name="action" value="add_member">
        <input name="nama" placeholder="Nama anggota" required>
        <input name="email" placeholder="Email (opsional)">
        <button class="btn" type="submit">Tambah</button>
    </form>
</div>

<div class="card">
    <h2>Daftar Anggota</h2>
    <table>
        <thead><tr><th>ID</th><th>Nama</th><th>Email</th><th>Joined</th></tr></thead>
        <tbody>
            <?php if (!empty($members)): ?>
                <?php foreach ($members as $m): ?>
                <tr>
                    <td><?php echo $m['id']; ?></td>
                    <td><?php echo htmlspecialchars($m['nama']); ?></td>
                    <td><?php echo htmlspecialchars($m['email'] ?: '-'); ?></td>
                    <td><?php echo shortDate($m['joined_at']); ?></td>
                </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="4" style="text-align:center">Belum ada data anggota.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<!-- ==================== HALAMAN PEMINJAMAN ==================== -->
<?php if ($page==='loans'): ?>
<div class="card">
    <h2>Pinjam Buku</h2>
    <form method="post" class="row">
        <input type="hidden" name="action" value="borrow">
        <select name="book_id" required>
            <option value="">-- Pilih Buku --</option>
            <?php foreach ($books as $b): if($b['status']=='tersedia'): ?>
                <option value="<?php echo $b['id']; ?>"><?php echo htmlspecialchars($b['judul']); ?></option>
            <?php endif; endforeach; ?>
        </select>
        <select name="member_id" required>
            <option value="">-- Pilih Anggota --</option>
            <?php foreach ($members as $m): ?>
                <option value="<?php echo $m['id']; ?>"><?php echo htmlspecialchars($m['nama']); ?></option>
            <?php endforeach; ?>
        </select>
        <button class="btn" type="submit">Pinjam</button>
    </form>
</div>

<div class="card">
    <h2>Daftar Peminjaman</h2>
    <table>
        <thead><tr><th>ID</th><th>Buku</th><th>Anggota</th><th>Pinjam</th><th>Deadline</th><th>Status</th></tr></thead>
        <tbody>
            <?php if (!empty($loans)): ?>
                <?php foreach ($loans as $l): ?>
                <tr>
                    <td><?php echo $l['id']; ?></td>
                    <td><?php echo htmlspecialchars($l['bookTitle']); ?></td>
                    <td><?php echo htmlspecialchars($l['memberName']); ?></td>
                    <td><?php echo shortDate($l['pinjam_at']); ?></td>
                    <td><?php echo shortDate($l['deadline_at']); ?></td>
                    <td><?php echo htmlspecialchars($l['status']); ?></td>
                </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="6" style="text-align:center">Belum ada peminjaman.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<!-- ==================== HALAMAN PENGEMBALIAN ==================== -->
<?php if ($page==='returns'): ?>
<div class="card">
    <h2>Kembalikan Buku</h2>
    <form method="post" class="row">
        <input type="hidden" name="action" value="return">
        <select name="loan_id" required>
            <option value="">-- Pilih Peminjaman --</option>
            <?php foreach ($loans as $l): if($l['status']=='dipinjam'): ?>
                <option value="<?php echo $l['id']; ?>"><?php echo htmlspecialchars($l['bookTitle'].' - '.$l['memberName']); ?></option>
            <?php endif; endforeach; ?>
        </select>
        <button class="btn" type="submit">Kembalikan</button>
    </form>
</div>

<div class="card">
    <h2>Daftar Pengembalian</h2>
    <table>
        <thead><tr><th>ID</th><th>Buku</th><th>Anggota</th><th>Pinjam</th><th>Kembali</th><th>Status</th></tr></thead>
        <tbody>
            <?php if (!empty($loans)): ?>
                <?php foreach ($loans as $l): ?>
                <tr>
                    <td><?php echo $l['id']; ?></td>
                    <td><?php echo htmlspecialchars($l['bookTitle']); ?></td>
                    <td><?php echo htmlspecialchars($l['memberName']); ?></td>
                    <td><?php echo shortDate($l['pinjam_at']); ?></td>
                    <td><?php echo shortDate($l['kembali_at']); ?></td>
                    <td><?php echo htmlspecialchars($l['status']); ?></td>
                </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="6" style="text-align:center">Belum ada pengembalian.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php if($debug): ?>
<div class="debug">
    <h3>DEBUG DATA</h3>
    <pre class="code">Books: <?php print_r($books); ?></pre>
    <pre class="code">Members: <?php print_r($members); ?></pre>
    <pre class="code">Loans: <?php print_r($loans); ?></pre>
</div>
<?php endif; ?>

</div>
</body>
</html>
