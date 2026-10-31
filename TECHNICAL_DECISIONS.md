# Keputusan Teknis — Warung Pak Hendy

Dokumen ini memisahkan kebutuhan yang dinyatakan dalam dokumen proyek dari rancangan teknis yang masih harus disetujui kelompok. Label **TERKONFIRMASI** berarti tertulis pada dokumen proyek/P07 atau instruksi kerja. Label **USULAN** berarti rancangan untuk ditinjau, bukan keputusan resmi.

## 1. Keputusan yang sudah terkonfirmasi

- **[TERKONFIRMASI]** Sistem memiliki empat komponen konseptual: POS / Kasir Service, Inventory Backend Service, Supplier Order Gateway, dan Dashboard Analytics & Laporan Keuangan.
- **[TERKONFIRMASI]** POS berkomunikasi dengan Inventory Backend menggunakan REST API sinkron. POS menunggu hasil pemrosesan transaksi.
- **[TERKONFIRMASI]** Inventory Backend dibangun dengan Laravel dan PostgreSQL. PostgreSQL digunakan, bukan MySQL.
- **[TERKONFIRMASI]** Inventory Backend mengelola data produk, perubahan stok, dan pencatatan riwayat transaksi; transaksi penjualan dikirim POS untuk divalidasi dan diproses oleh backend.
- **[TERKONFIRMASI]** Inventory Backend memantau stok terhadap batas minimum. Ketika kondisi stok minimum terpenuhi, backend menerbitkan event melalui RabbitMQ secara asinkron kepada Supplier Order Gateway untuk tindak lanjut restock.
- **[TERKONFIRMASI]** Dashboard menyajikan rekap penjualan, produk terlaris, informasi keuangan, dan kebutuhan restock. Mekanisme integrasinya belum ditentukan.
- **[TERKONFIRMASI]** Tahap implementasi backend menggunakan Laravel Sail dan RabbitMQ. Frontend POS/dashboard tidak termasuk scope implementasi checkpoint ini berdasarkan instruksi proyek. P07 menyatakan autentikasi belum dinilai pada Checkpoint 1, sehingga autentikasi bukan prioritas implementasi checkpoint ini.
- **[TERKONFIRMASI]** P07 mensyaratkan `openapi.yaml` design-first dan lolos Spectral, skema operasi dan galat Problem Details, kontrak event lengkap, sekurang-kurangnya satu layanan berjalan, feature test, README, serta demonstrasi request sukses/gagal dan event.
- **[TERKONFIRMASI]** Dokumen analisis kelompok saat ini 10 halaman, sementara P07 membatasi dokumen analisis menjadi 4–8 halaman.

## 2. Usulan teknis yang perlu persetujuan kelompok

- **[USULAN]** Gunakan prefix `/api/v1` untuk endpoint publik agar perubahan kontrak berikutnya dapat diberi versi.
- **[USULAN]** Gunakan resource `products` dan `transactions`; transaksi penjualan direpresentasikan sebagai satu transaksi dengan daftar item.
- **[USULAN]** Gunakan ID UUID untuk produk/transaksi/event. Pastikan keputusan ini cocok dengan kebutuhan kelompok sebelum dimasukkan ke OpenAPI atau migrasi.
- **[USULAN]** Pakai respons JSON dan format RFC 9457 Problem Details dengan media type `application/problem+json` untuk kegagalan API. Kode status dan bentuk field rinci harus disepakati serta dicantumkan di OpenAPI.
- **[USULAN]** Publikasikan event setelah transaksi dan perubahan stok tersimpan. Pertimbangkan transactional outbox bila kelompok menghendaki jaminan konsistensi database-broker; ini menambah cakupan implementasi.
- **[USULAN]** Gunakan database transaction PostgreSQL untuk menyimpan transaksi penjualan dan mengurangi stok secara atomik: kedua perubahan berhasil bersama atau dibatalkan bersama. Persetujuan diperlukan atas perilaku kegagalan dan aturan stok yang mendahului pengurangan.
- **[USULAN]** Gunakan event exchange bertipe topic serta queue khusus untuk Supplier Order Gateway. Nama exchange, routing key, queue, retry, dead-letter, dan kebijakan deduplikasi perlu disepakati.
- **[USULAN]** Gunakan operasi REST dan skema event dalam dokumen ini sebagai rancangan awal untuk ditinjau. Jangan menganggap nama, field, validasi, atau status HTTP di bawah telah disetujui.
- **[USULAN]** Tunda integrasi Dashboard sampai kelompok menetapkan sumber data dan mekanismenya (misalnya REST atau event); jangan menghubungkannya langsung ke database tanpa keputusan arsitektur.

## 3. Rancangan endpoint REST

Dokumen domain menetapkan alur transaksi dan pertukaran data produk/stok, tetapi tidak menetapkan path, metode, payload, maupun kode respons. Semua endpoint dan detail kontrak pada bagian ini adalah **USULAN**.

### 3.1 Produk

| Label | Metode dan path | Tujuan rancangan | Catatan kontrak yang perlu disepakati |
|---|---|---|---|
| **[USULAN]** | `GET /api/v1/products` | Membaca katalog produk beserta stok untuk POS. | Apakah pencarian, filter, pagination, dan produk nonaktif diperlukan? Bentuk item dan satuan stok belum ditentukan. |
| **[USULAN]** | `GET /api/v1/products/{productId}` | Membaca satu produk dan informasi stoknya. | Tipe ID, field produk, dan respons saat produk tidak ditemukan perlu disepakati. |

> Endpoint tulis untuk produk, misalnya membuat atau mengubah produk, tidak ditambahkan dalam rancangan awal karena dokumen yang tersedia tidak menjelaskan aktor atau alur administrasi katalog.

### 3.2 Transaksi penjualan

| Label | Metode dan path | Tujuan rancangan | Catatan kontrak yang perlu disepakati |
|---|---|---|---|
| **[USULAN]** | `POST /api/v1/transactions` | Menerima transaksi dari POS; backend memvalidasi, mencatat transaksi, memperbarui stok, lalu mengembalikan hasil. | Struktur item, harga, kuantitas, timestamp, metode pembayaran, aturan stok, idempotency key, dan atomisitas transaksi belum ditentukan dokumen. |

**[USULAN]** Bentuk request konseptual untuk dibahas (bukan kontrak final):

```json
{
  "items": [
    {
      "productId": "<product-id>",
      "quantity": 2
    }
  ]
}
```

**[USULAN]** Sukses dapat mengembalikan ID transaksi, status pemrosesan, dan ringkasan hasil. Kode `201` atau `200` harus dipilih berdasarkan semantik yang disepakati dan ditetapkan di OpenAPI. Kegagalan validasi dapat memakai `400` atau `422` sesuai konvensi yang dipilih; gunakan format Problem Details. Jangan menetapkan aturan seperti stok negatif ditolak, harga dikunci saat transaksi, atau pembayaran wajib sebelum kelompok menyepakati aturan tersebut.

**[USULAN]** Jalankan pencatatan transaksi dan pengurangan stok dalam satu database transaction PostgreSQL. Ini menjaga keduanya atomik saat penyimpanan, tetapi tidak menetapkan sendiri aturan bisnis ketika stok kurang atau boleh negatif. Perlu diputuskan bagaimana transaksi di-rollback jika salah satu langkah gagal dan bagaimana event RabbitMQ dipublikasikan setelah commit.

### 3.3 Operasi yang belum masuk rancangan endpoint

- **[TERKONFIRMASI]** Dashboard membutuhkan data penjualan, persediaan, dan ringkasan operasional.
- **[TERKONFIRMASI]** Dokumen belum menetapkan mekanisme integrasi Dashboard.
- **[USULAN]** Endpoint laporan, endpoint konfigurasi threshold, dan endpoint status restock ditahan sampai kebutuhan, aktor, dan perilakunya dikonfirmasi.

## 4. Rancangan event `StockMinimumReached`

- **[TERKONFIRMASI]** Pemicu bisnis adalah kondisi stok mencapai batas minimum yang ditetapkan.
- **[TERKONFIRMASI]** Dokumen tidak menetapkan operator perbandingan untuk menentukan kapan threshold tercapai; kondisi tepatnya perlu diputuskan.
- **[TERKONFIRMASI]** Publisher adalah Inventory Backend Service; event dikirim asinkron melalui RabbitMQ; Supplier Order Gateway adalah penerima/consumer yang dituju.
- **[TERKONFIRMASI]** P07 mewajibkan nama event berbentuk lampau serta kontrak yang mencakup exchange, routing key, field dan tipe, publisher, dan consumer.
- **[USULAN]** Nama `StockMinimumReached` dipilih untuk rancangan ini karena menyatakan kejadian yang sudah terjadi. Nama final tetap harus disetujui kelompok.
- **[USULAN]** Gunakan exchange topic `inventory.events` dan routing key `inventory.stock.minimum_reached`. Keduanya belum muncul sebagai keputusan pada dokumen proyek.
- **[USULAN]** Gunakan queue `supplier-order.stock-minimum-reached` untuk consumer Supplier Order Gateway. Nama queue juga belum ditentukan dokumen.
- **[USULAN]** Pesan menyampaikan fakta bahwa stok mencapai minimum; pesan ini tidak dengan sendirinya berarti pesanan supplier sudah disetujui atau dikirim.

### Kondisi pemicu threshold

- **[USULAN]** `stock == threshold` hanya menerbitkan event saat jumlah stok sama persis dengan batas minimum. Ini dapat merepresentasikan titik saat stok mencapai batas, tetapi dapat melewatkan kondisi bila stok berubah dari di atas threshold ke di bawahnya dalam satu pembaruan.
- **[USULAN]** `stock <= threshold` menerbitkan event ketika jumlah stok berada pada atau di bawah batas minimum. Ini mencakup kondisi stok yang melewati threshold, tetapi perlu pencegahan agar event tidak diterbitkan berulang kali pada setiap pembaruan selama stok tetap di bawah threshold.
- **[USULAN]** Rekomendasi awal: gunakan transisi dari `stock_before > threshold` ke `stock_after <= threshold` sebagai pemicu. Ini menangkap stok yang menyentuh atau melewati batas tanpa mengulang event untuk setiap perubahan berikutnya di bawah batas. Kelompok perlu menyetujui definisi transisi, aturan penerbitan ulang setelah restock, dan penanganan duplikasi.

### Payload usulan

**[USULAN]** Contoh berikut hanya rancangan awal. Tipe data, format timestamp, satuan kuantitas, ID, dan kebutuhan field harus dikonfirmasi sebelum kontrak event dibekukan.

```json
{
  "eventId": "<uuid>",
  "eventType": "StockMinimumReached",
  "occurredAt": "2026-01-01T12:00:00Z",
  "product": {
    "id": "<product-id>",
    "sku": "<sku>",
    "name": "<product-name>"
  },
  "stock": {
    "quantity": 4,
    "minimumThreshold": 5,
    "unit": "<unit>"
  }
}
```

| Field | Tipe usulan | Tujuan | Status |
|---|---|---|---|
| `eventId` | string berformat UUID | Identitas unik event; dapat membantu deduplikasi. | **USULAN** |
| `eventType` | string | Nama tipe event. | **USULAN** |
| `occurredAt` | string tanggal-waktu RFC 3339/ISO 8601 | Waktu kondisi threshold terdeteksi. | **USULAN** |
| `product.id` | string berformat UUID atau ID yang dipilih | Referensi produk. | **USULAN** |
| `product.sku` | string | Kode produk, jika domain memilikinya. | **USULAN** |
| `product.name` | string | Nama produk untuk membantu consumer. | **USULAN** |
| `stock.quantity` | number (integer atau desimal perlu diputuskan) | Jumlah stok saat event terbit. | **USULAN** |
| `stock.minimumThreshold` | number (integer atau desimal perlu diputuskan) | Nilai threshold yang dibandingkan. | **USULAN** |
| `stock.unit` | string | Satuan stok, bila relevan. | **USULAN** |

**[USULAN]** Keputusan yang masih perlu dibuat untuk reliabilitas: kapan event dianggap terbit relatif terhadap commit database, apa yang dilakukan bila publish gagal, kebijakan retry/dead-letter, bagaimana consumer mengakui pesan, dan bagaimana event duplikat ditangani. Dokumen kelompok mengidentifikasi kegagalan, retry, dan duplikasi sebagai risiko, tetapi tidak menentukan konfigurasi teknisnya.

## 5. Rancangan alur pengujian Postman

P07 meminta demo permintaan berhasil dan gagal dengan Problem Details; urutan dan detail berikut adalah **USULAN** untuk memenuhi kebutuhan tersebut.

1. **[USULAN]** Buat environment Postman dengan `baseUrl` dan ID yang dapat diisi setelah layanan tersedia; jangan menyimpan password/token nyata.
2. **[USULAN]** Panggil `GET /api/v1/products`; periksa status sukses, `Content-Type` JSON, dan bentuk respons yang telah disepakati di OpenAPI.
3. **[USULAN]** Panggil `GET /api/v1/products/{productId}` dengan ID uji yang ada; periksa identitas dan field produk sesuai kontrak.
4. **[USULAN]** Kirim `POST /api/v1/transactions` dengan transaksi uji valid; periksa kode status dan skema respons sesuai OpenAPI. Bila transaksi mengurangi stok menurut aturan yang disepakati, baca ulang produk untuk memeriksa perubahan itu.
5. **[USULAN]** Kirim transaksi tidak valid berdasarkan aturan yang telah disepakati, misalnya field wajib hilang. Periksa kode status, `Content-Type: application/problem+json`, dan field Problem Details sesuai kontrak. Skenario ini tidak menentukan aturan stok atau pembayaran.
6. **[USULAN]** Jalankan satu alur yang menyebabkan stok mencapai threshold yang dikonfigurasi. Verifikasi event dengan consumer/log/alat inspeksi broker; Postman sendiri tidak membuktikan bahwa event berhasil diproses oleh RabbitMQ.
7. **[USULAN]** Simpan contoh request/response dan environment non-secret untuk demo. Uji Postman harus mengikuti `openapi.yaml` yang telah lolos Spectral.

**[TERKONFIRMASI]** Demonstrasi P07 perlu menunjukkan satu permintaan berhasil, satu gagal dengan Problem Details, `sail artisan test`, serta satu event terkirim atau diterima. Persiapan demo perlu memastikan layanan dan RabbitMQ telah berjalan sebelum giliran presentasi.

## 6. Rancangan struktur proyek Laravel

Bagian ini adalah **USULAN** struktur awal untuk satu layanan Inventory Backend Laravel. Nama dan pembagian kelas dapat disesuaikan setelah kontrak disetujui; ini bukan struktur yang diwajibkan dokumen.

```text
.
├── app/
│   ├── Events/
│   │   └── StockMinimumReached.php
│   ├── Http/
│   │   ├── Controllers/Api/V1/
│   │   │   ├── ProductController.php
│   │   │   └── TransactionController.php
│   │   ├── Requests/Api/V1/
│   │   │   └── StoreTransactionRequest.php
│   │   └── Resources/Api/V1/
│   │       ├── ProductResource.php
│   │       └── TransactionResource.php
│   ├── Listeners/
│   ├── Models/
│   │   ├── Product.php
│   │   ├── Transaction.php
│   │   └── TransactionItem.php
│   └── Services/
│       ├── ProductService.php
│       └── TransactionService.php
├── config/
│   ├── database.php
│   └── queue.php
├── database/
│   ├── migrations/
│   ├── factories/
│   └── seeders/
├── openapi.yaml
├── routes/
│   └── api.php
├── tests/Feature/Api/V1/
│   ├── ProductApiTest.php
│   └── TransactionApiTest.php
├── compose.yaml
├── .env.example
├── README.md
└── TECHNICAL_DECISIONS.md
```

- **[USULAN]** `Product`, `Transaction`, dan `TransactionItem` adalah pemisahan model awal; nama tabel, relasi, kolom, dan penggunaan decimal/integer untuk stok/harga menunggu skema domain yang disepakati.
- **[USULAN]** `StockMinimumReached` menjadi event domain yang diterbitkan oleh layanan transaksi/stok. Integrasi RabbitMQ dapat ditempatkan pada driver queue atau adapter khusus setelah paket/driver dipilih.
- **[USULAN]** Gunakan `compose.yaml` untuk konfigurasi Docker Compose Laravel Sail yang menjalankan aplikasi, PostgreSQL, dan RabbitMQ; image, port, kredensial development, health check, dan persistensi perlu ditetapkan saat implementasi. `.env.example` hanya berisi placeholder aman.
- **[USULAN]** Simpan kontrak OpenAPI di root repository sebagai `openapi.yaml`.
- **[TERKONFIRMASI]** Autentikasi belum dinilai pada Checkpoint 1 menurut P07.
- **[USULAN]** Prioritaskan API, PostgreSQL, RabbitMQ/event, feature test, dokumentasi, dan demo checkpoint sebelum implementasi autentikasi.
- **[USULAN]** Tambahkan dokumentasi event (misalnya di README atau `docs/events.md`) dengan nama, exchange, routing key, skema field, publisher, dan consumer.

## 7. Keputusan yang diminta dari kelompok

1. **[USULAN]** Setujui atau ubah endpoint produk dan transaksi, berikut path, field, validasi, serta kode respons.
2. **[USULAN]** Tetapkan skema ID produk, definisi stok/satuan, threshold, dan kondisi persis pemicu event.
3. **[USULAN]** Setujui nama event, exchange, routing key, queue, payload, timestamp, tipe kuantitas, serta strategi retry/deduplikasi.
4. **[USULAN]** Putuskan apakah transaksi dan perubahan stok harus tersimpan atomik serta apakah transactional outbox masuk scope.
5. **[USULAN]** Sepakati penggunaan database transaction PostgreSQL untuk atomisitas transaksi dan pengurangan stok, serta perilaku rollback dan publikasi event setelah commit.
6. **[USULAN]** Tentukan operator dan transisi threshold, termasuk aturan event ulang setelah restock dan pencegahan event berulang.
7. **[USULAN]** Tentukan mekanisme integrasi Dashboard pada tahap selanjutnya.
8. **[USULAN]** Pangkas dokumen analisis 10 halaman menjadi 4–8 halaman sesuai P07.
