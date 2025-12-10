-- ==========================
-- Database & Pengaturan
-- ==========================
CREATE DATABASE IF NOT EXISTS perpustakaan_pastel
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
USE perpustakaan_pastel;

-- ==========================
-- Tabel: books
-- ==========================
DROP TABLE IF EXISTS books;
CREATE TABLE books (
  id INT AUTO_INCREMENT PRIMARY KEY,
  judul VARCHAR(250) NOT NULL,
  judul_upper VARCHAR(250) NOT NULL,
  slug VARCHAR(100) NOT NULL,
  kategori VARCHAR(80) NOT NULL,
  `status` ENUM('tersedia','dipinjam') NOT NULL DEFAULT 'tersedia',
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  UNIQUE KEY uq_slug (slug),
  INDEX idx_status ( `status` ),
  INDEX idx_kategori ( kategori )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==========================
-- Tabel: members
-- ==========================
DROP TABLE IF EXISTS members;
CREATE TABLE members (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nama VARCHAR(200) NOT NULL,
  email VARCHAR(255) DEFAULT NULL,
  joined_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  UNIQUE KEY uq_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==========================
-- Tabel: loans
-- ==========================
DROP TABLE IF EXISTS loans;
CREATE TABLE loans (
  id INT AUTO_INCREMENT PRIMARY KEY,
  book_id INT NOT NULL,
  member_id INT NOT NULL,
  book_title VARCHAR(250) NOT NULL,
  member_name VARCHAR(200) NOT NULL,
  pinjam_at DATETIME(6) NOT NULL,
  deadline_at DATETIME(6) NOT NULL,
  kembali_at DATETIME(6) DEFAULT NULL,
  `status` ENUM('dipinjam','kembali','telat') NOT NULL DEFAULT 'dipinjam',
  INDEX idx_book_id (book_id),
  INDEX idx_member_id (member_id),
  CONSTRAINT fk_loans_book FOREIGN KEY (book_id)
    REFERENCES books(id) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_loans_member FOREIGN KEY (member_id)
    REFERENCES members(id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==========================
-- Tabel: logs (aktivitas serupa log.txt)
-- ==========================
DROP TABLE IF EXISTS logs;
CREATE TABLE logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  level ENUM('INFO','WARN','ERROR') NOT NULL DEFAULT 'INFO',
  message TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ==========================
-- Stored procedure: add_book
-- (mencerminkan add_book php)
-- ==========================
DROP PROCEDURE IF EXISTS sp_add_book;
DELIMITER $$
CREATE PROCEDURE sp_add_book(
  IN p_judul VARCHAR(250),
  IN p_kategori VARCHAR(80)
)
BEGIN
  DECLARE v_slug VARCHAR(100);
  DECLARE v_judul_upper VARCHAR(250);

  IF TRIM(p_judul) = '' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Judul buku wajib diisi.';
  END IF;

  IF TRIM(p_kategori) = '' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Kategori buku wajib diisi.';
  END IF;

  SET v_judul_upper = UPPER(p_judul);
  -- sederhana: buat slug by replacing non-alnum with dash and limit length
  SET v_slug = LOWER(
    LEFT(REGEXP_REPLACE(p_judul, '[^a-zA-Z0-9]+', '-'), 100)
  );

  INSERT INTO books (judul, judul_upper, slug, kategori, `status`, created_at)
  VALUES (p_judul, v_judul_upper, v_slug, p_kategori, 'tersedia', NOW(6));

  INSERT INTO logs(level, message) VALUES ('INFO', CONCAT('Tambah Buku: ', p_judul, ' (kategori: ', p_kategori, ')'));
END $$
DELIMITER ;

-- ==========================
-- Stored procedure: edit_book
-- ==========================
DROP PROCEDURE IF EXISTS sp_edit_book;
DELIMITER $$
CREATE PROCEDURE sp_edit_book(
  IN p_book_id INT,
  IN p_judul VARCHAR(250),
  IN p_kategori VARCHAR(80)
)
BEGIN
  DECLARE v_count INT DEFAULT 0;
  DECLARE v_slug VARCHAR(100);
  DECLARE v_judul_upper VARCHAR(250);

  IF p_book_id IS NULL OR p_book_id = 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'book_id tidak valid';
  END IF;

  SELECT COUNT(*) INTO v_count FROM books WHERE id = p_book_id;
  IF v_count = 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Buku tidak ditemukan.';
  END IF;

  SET v_judul_upper = UPPER(p_judul);
  SET v_slug = LOWER(LEFT(REGEXP_REPLACE(p_judul, '[^a-zA-Z0-9]+', '-'), 100));

  UPDATE books
  SET judul = p_judul,
      judul_upper = v_judul_upper,
      slug = v_slug,
      kategori = p_kategori
  WHERE id = p_book_id;

  INSERT INTO logs(level, message) VALUES ('INFO', CONCAT('Edit Buku: ', p_book_id, ' - ', p_judul));
END $$
DELIMITER ;

-- ==========================
-- Stored procedure: delete_book
-- (hanya jika tersedia)
-- ==========================
DROP PROCEDURE IF EXISTS sp_delete_book;
DELIMITER $$
CREATE PROCEDURE sp_delete_book(
  IN p_book_id INT
)
BEGIN
  DECLARE v_status VARCHAR(20);
  DECLARE v_title VARCHAR(250);

  SELECT `status`, judul INTO v_status, v_title FROM books WHERE id = p_book_id;

  IF v_status IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Buku tidak ditemukan.';
  END IF;

  IF v_status <> 'tersedia' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Buku hanya bisa dihapus kalau status = tersedia.';
  END IF;

  DELETE FROM books WHERE id = p_book_id;
  INSERT INTO logs(level, message) VALUES ('INFO', CONCAT('Hapus Buku: ', p_book_id, ' - ', v_title));
END $$
DELIMITER ;

-- ==========================
-- Stored procedure: borrow (pinjam)
-- - menambahkan record di loans
-- - update books.status = 'dipinjam'
-- - menetapkan deadline = pinjam_at + INTERVAL 3 DAY (sama seperti PHP)
-- - dijalankan dalam transaksi
-- ==========================
DROP PROCEDURE IF EXISTS sp_borrow;
DELIMITER $$
CREATE PROCEDURE sp_borrow(
  IN p_book_id INT,
  IN p_member_id INT
)
BEGIN
  DECLARE v_book_status VARCHAR(20);
  DECLARE v_book_title VARCHAR(250);
  DECLARE v_member_name VARCHAR(200);
  DECLARE v_now DATETIME(6);
  DECLARE v_deadline DATETIME(6);

  START TRANSACTION;

  -- cek buku & status
  SELECT `status`, judul INTO v_book_status, v_book_title FROM books WHERE id = p_book_id FOR UPDATE;
  IF v_book_status IS NULL THEN
    ROLLBACK;
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Buku tidak ditemukan.';
  END IF;

  IF v_book_status <> 'tersedia' THEN
    ROLLBACK;
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = CONCAT('Buku tidak tersedia (status: ', v_book_status, ').');
  END IF;

  -- cek member
  SELECT nama INTO v_member_name FROM members WHERE id = p_member_id;
  IF v_member_name IS NULL THEN
    ROLLBACK;
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Anggota tidak ditemukan.';
  END IF;

  SET v_now = NOW(6);
  SET v_deadline = v_now + INTERVAL 3 DAY;

  INSERT INTO loans (book_id, member_id, book_title, member_name, pinjam_at, deadline_at, `status`)
  VALUES (p_book_id, p_member_id, v_book_title, v_member_name, v_now, v_deadline, 'dipinjam');

  UPDATE books SET `status` = 'dipinjam' WHERE id = p_book_id;

  INSERT INTO logs(level, message) VALUES ('INFO', CONCAT('Pinjam: Buku[', p_book_id, '] oleh Member[', p_member_id, ']'));

  COMMIT;
END $$
DELIMITER ;

-- ==========================
-- Stored procedure: return (pengembalian)
-- - update loans.kembali_at, loans.status
-- - update books.status = 'tersedia'
-- - menghitung telat berdasarkan deadline_at < NOW()
-- ==========================
DROP PROCEDURE IF EXISTS sp_return;
DELIMITER $$
CREATE PROCEDURE sp_return(
  IN p_loan_id INT
)
BEGIN
  DECLARE v_book_id INT;
  DECLARE v_deadline DATETIME(6);
  DECLARE v_now DATETIME(6);
  DECLARE v_status_loans VARCHAR(20);
  DECLARE v_member_id INT;

  START TRANSACTION;

  SELECT book_id, member_id, deadline_at, `status` INTO v_book_id, v_member_id, v_deadline, v_status_loans
  FROM loans WHERE id = p_loan_id FOR UPDATE;

  IF v_book_id IS NULL THEN
    ROLLBACK;
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Peminjaman tidak ditemukan.';
  END IF;

  IF v_status_loans <> 'dipinjam' THEN
    -- jika sudah dikembalikan sebelumnya, batalkan
    ROLLBACK;
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Peminjaman ini bukan status dipinjam.';
  END IF;

  SET v_now = NOW(6);

  -- tentukan status pengembalian
  IF v_deadline < v_now THEN
    UPDATE loans
      SET kembali_at = v_now,
          `status` = 'telat'
    WHERE id = p_loan_id;
    INSERT INTO logs(level, message) VALUES ('WARN', CONCAT('Kembalikan (TELAT): Loan[', p_loan_id, '] Buku[', v_book_id, '] oleh Member[', v_member_id, ']'));
  ELSE
    UPDATE loans
      SET kembali_at = v_now,
          `status` = 'kembali'
    WHERE id = p_loan_id;
    INSERT INTO logs(level, message) VALUES ('INFO', CONCAT('Kembalikan (ON TIME): Loan[', p_loan_id, '] Buku[', v_book_id, '] oleh Member[', v_member_id, ']'));
  END IF;

  -- set buku tersedia kembali
  UPDATE books SET `status` = 'tersedia' WHERE id = v_book_id;

  COMMIT;
END $$
DELIMITER ;

-- ==========================
-- Trigger: logs otomatis ketika ada insert ke loans (optional)
-- Catatan: sudah ada logging di prosedur, tetapi trigger menambah lapisan bila ada insert manual
-- ==========================
DROP TRIGGER IF EXISTS trg_loans_after_insert;
DELIMITER $$
CREATE TRIGGER trg_loans_after_insert
AFTER INSERT ON loans
FOR EACH ROW
BEGIN
  INSERT INTO logs(level, message)
    VALUES ('INFO', CONCAT('Loan CREATED: Loan[', NEW.id, '] Book[', NEW.book_id, '] Member[', NEW.member_id, ']'));
END $$
DELIMITER ;

-- ==========================
-- Contoh data awal (opsional)
-- ==========================
INSERT INTO books (judul, judul_upper, slug, kategori, `status`) VALUES
('Belajar PHP untuk Pemula', UPPER('Belajar PHP untuk Pemula'), 'belajar-php-untuk-pemula', 'Pendidikan', 'tersedia'),
('Novel Senja', UPPER('Novel Senja'), 'novel-senja', 'Novel', 'tersedia');

INSERT INTO members (nama, email) VALUES
('Ainul Hakimah', 'ainul@example.com'),
('Budi Santoso', 'budi@example.com');

-- ==========================
-- Contoh panggilan prosedur
-- ==========================
-- 1) Tambah buku via sp:
-- CALL sp_add_book('Judul Baru', 'Referensi');

-- 2) Edit buku:
-- CALL sp_edit_book(1, 'Belajar PHP Lanjutan', 'Pendidikan');

-- 3) Hapus buku (hanya kalau tersedia):
-- CALL sp_delete_book(2);

-- 4) Pinjam buku (book_id, member_id):
-- CALL sp_borrow(1, 1);

-- 5) Kembalikan (loan_id):
-- CALL sp_return(1);

-- ==========================
-- Query bantu / view
-- ==========================
-- Buku tersedia:
-- SELECT * FROM books WHERE `status` = 'tersedia';

-- Daftar pinjaman aktif:
-- SELECT * FROM loans WHERE `status` = 'dipinjam' ORDER BY pinjam_at DESC;

-- Lihat log terakhir:
-- SELECT * FROM logs ORDER BY created_at DESC LIMIT 50;
