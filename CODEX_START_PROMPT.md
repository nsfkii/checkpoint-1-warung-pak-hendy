# Instruksi Awal Codex — Checkpoint 1 Warung Pak Hendy

Kamu bekerja di repository baru untuk proyek kelompok **Sistem Manajemen Operasional & Kasir Digital Warung Pak Hendy**. Ini BUKAN proyek praktik dosen dan BUKAN kelanjutan proyek `toko-stok`. Jangan menyalin asumsi, nama event, exchange, routing key, konfigurasi, atau kode dari proyek praktik tersebut.

## Sumber kebenaran
Baca seluruh dokumen berikut sebelum mengusulkan implementasi:
1. `references/CheckPoint1_Proyek Akhir_Kel_67.pdf` — dokumen analisis kelompok.
2. `references/P07 - Panduan Checkpoint 1 Proyek Akhir.docx` — persyaratan dan rubrik resmi checkpoint.
3. `references/P03 - Lembar Kerja Tugas 3 - Memilih Gaya Integrasi(1).pdf` — hasil pemetaan sistem dan pemilihan gaya integrasi.

Prioritaskan dokumen kelompok untuk keputusan domain/arsitektur dan panduan P07 untuk kriteria penilaian. Jangan mengarang detail yang tidak tertulis. Jika ada konflik atau detail kontrak belum ditetapkan (termasuk nama event, exchange, routing key, payload, operasi endpoint, atau konfigurasi), catat sebagai keputusan yang perlu dikonfirmasi; jangan diam-diam memilih sendiri.

## Konteks yang sudah diketahui dari dokumen
- Sistem: Sistem Manajemen Operasional & Kasir Digital “Warung Pak Hendy”.
- Empat komponen konseptual: Aplikasi Kasir/POS; Backend Core & Database Inventori (Tugas 3 menyebut Laravel + PostgreSQL); Supplier Order Gateway; Dashboard Analytics & Laporan Keuangan.
- POS ↔ Backend Inventori menggunakan komunikasi sinkron REST/RPI.
- Backend Inventori ↔ Supplier Order Gateway menggunakan messaging asinkron melalui message broker.
- P07 meminta `openapi.yaml` design-first dan lolos Spectral, Problem Details, kontrak event (nama bentuk lampau, exchange, routing key, fields/types, publisher/consumer), layanan Laravel Sail yang berjalan, feature tests, README, dan demonstrasi.
- Target kerja kelompok saat ini: implementasi baru sampai REST API dapat diuji berhasil di Postman, sambil tetap memenuhi bagian layanan berjalan dan event yang diwajibkan P07.

## Batasan kerja
- Repository ini harus dibangun bersih dari awal. Jangan mencari, membaca, atau menyalin folder proyek praktik `toko-stok`.
- Jangan membuat frontend POS/dashboard kecuali dokumen checkpoint secara eksplisit mewajibkannya untuk tahap ini. Fokus layanan backend yang menjadi layanan berjalan.
- Jangan menambahkan autentikasi karena P07 menyatakan autentikasi belum dinilai pada Checkpoint 1, kecuali ada keputusan kelompok yang mengubah ruang lingkup.
- Gunakan PostgreSQL sesuai Tugas 3, kecuali setelah membaca dokumen lengkap ditemukan keputusan kelompok yang lebih baru dan eksplisit.
- Jangan membuat kontrak API/event berdasarkan tebakan. Turunkan kebutuhan dari dokumen; tandai detail yang belum ada untuk konfirmasi.
- Jangan menjalankan perintah destruktif, menghapus file, menghapus volume Docker, mereset database, atau melakukan force push.
- Jangan menaruh secret/password/token nyata di source control. Siapkan `.env.example` dengan placeholder aman.
- Jangan mengklaim test atau API berhasil sebelum benar-benar menjalankannya dan memeriksa hasilnya.

## Tugas untuk sesi pertama — ANALISIS SAJA
1. Periksa isi folder repository dan pastikan ini proyek baru. Jangan mengubah atau membuat file aplikasi dahulu.
2. Baca ketiga dokumen referensi secara menyeluruh.
3. Buat ringkasan kebutuhan checkpoint berdasarkan dokumen, dengan bagian:
   - batas ruang lingkup layanan yang perlu berjalan;
   - entitas/data utama yang didukung dokumen;
   - kandidat operasi REST yang memang dapat diturunkan dari dokumen;
   - alur sinkron POS → Backend Inventori;
   - alur asinkron Backend Inventori → Supplier Order Gateway;
   - persyaratan OpenAPI, Problem Details, Spectral, RabbitMQ, Laravel Sail, feature test, README, dan demo;
   - hal yang belum ditentukan dan perlu persetujuan kelompok.
4. Buat usulan tahapan implementasi yang berurutan sampai API dapat diuji di Postman.
5. Identifikasi risiko ketidaksesuaian antara dokumen analisis, Tugas 3, dan P07. Jangan menyelesaikannya dengan asumsi.
6. Tampilkan hasil analisis dan minta persetujuan sebelum membuat/mengubah file aplikasi atau menjalankan instalasi.

## Cara bekerja setelah sesi analisis disetujui
Kerjakan satu tahap pada satu waktu. Sebelum perubahan besar, jelaskan file/perintah yang akan dibuat atau dijalankan. Setelah setiap tahap, jalankan pemeriksaan yang relevan dan laporkan hasil aktual. Urutan prinsip: kontrak OpenAPI → validasi/lint Spectral → feature tests → implementasi Laravel → pengujian integrasi database → RabbitMQ/event → pengujian Postman → README dan bukti demo. Sesuaikan urutan bila dokumen resmi menetapkan lain.
