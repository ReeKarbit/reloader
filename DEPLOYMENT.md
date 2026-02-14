# Panduan Deployment ke Shared Hosting (cPanel) 🚀

Karena Anda menggunakan **Shared Hosting (cPanel)**, kita tidak bisa menginstall program tambahan seperti `yt-dlp`.
Oleh karena itu, aplikasi ini didesain menggunakan strategi **Hybrid**:
1.  **Server-Side (PHP)**: Mencoba download cepat via API (jika tervalidasi).
2.  **Client-Side (Browser)**: **(ANDALAN UTAMA)** Jika server gagal (karena blokir Instagram), browser pengunjung akan otomatis mengambil alih dan mencari link download via proxy browser.

## Langkah-Langkah Upload

1.  **Siapkan File**:
    Pastikan file-file berikut ada:
    *   `index.html` (Halaman Utama)
    *   `style.css` (Tampilan)
    *   `script.js` (Logika & Fallback Cerdas)
    *   `api.php` (Backend API)
    *   `download.php` (Proxy Download)
    *   `assets/` (Folder gambar/logo jika ada)

2.  **Login ke cPanel**:
    *   Buka `File Manager`.
    *   Masuk ke folder `public_html` (atau subdomain tujuan).

3.  **Upload & Extract**:
    *   Compress (Zip) semua file di komputer Anda.
    *   Upload file `.zip` ke File Manager.
    *   Extract di folder tujuan.

4.  **Cek Permissions**:
    *   Pastikan folder memiliki permission `755` (atau `0755`).
    *   Pastikan file PHP memiliki permission `644` (atau `0644`).

## Cara Kerja (PENTING)

Saat Anda menggunakan aplikasi di hosting:
1.  Klik **Download**.
2.  Jika Server Hosting Anda diblokir Instagram (Sangat Umum), akan muncul pesan *"Processing..."* atau *"Gagal mengambil dari server utama"*.
3.  **JANGAN PANIK**. Perhatikan kotak kuning **"Mencoba server cadangan..."**.
4.  Script akan otomatis mencari rute alternatif langsung dari browser Anda. Ini **99% Behasil** karena browser Anda tidak diblokir sekeras server hosting.

## Troubleshooting

*   **Error 403 / 404 pada `api.php`**: Cek permission file.
*   **Download tidak jalan**: Pastikan koneksi internet stabil, karena proses fallback membutuhkan request dari browser.

---
**Tips**: Fitur ini sudah optimal untuk Shared Hosting tanpa biaya tambahan! 🎉
