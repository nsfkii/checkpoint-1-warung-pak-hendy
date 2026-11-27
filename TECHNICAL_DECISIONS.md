# Keputusan Teknis — Warung Pak Hendy

Dokumen ini mencatat keputusan pemilik, persyaratan proyek, rekomendasi teknis, status implementasi, dan hal yang masih pending. Rekomendasi teknis bukan keputusan bisnis baru. Dokumen ini bukan pengganti migration atau kode layanan.

Label: **TERKONFIRMASI** = keputusan pemilik/kebutuhan P07 atau P03; **REKOMENDASI TEKNIS** = pilihan implementasi yang perlu direview; **PENDING** = perlu konfirmasi, bukan keputusan final.

## 1. Keputusan terkonfirmasi

### 1.1 Arsitektur dan bisnis

- **[TERKONFIRMASI]** Empat komponen konseptual: POS/Kasir, Inventory Backend, Supplier Order Gateway, Dashboard Analytics & Laporan Keuangan.
- **[TERKONFIRMASI]** POS berkomunikasi sinkron dengan Inventory Backend lewat REST dan menunggu hasil transaksi.
- **[TERKONFIRMASI]** Inventory Backend menggunakan Laravel dan PostgreSQL, mengelola produk, stok, dan riwayat transaksi.
- **[TERKONFIRMASI]** Produk mendukung create, read daftar/detail, update, dan delete; memiliki `unit` seperti pcs, botol, atau bungkus. Kuantitas dan stok adalah bilangan bulat (`INTEGER`).
- **[TERKONFIRMASI]** `products.name` memakai `VARCHAR(160)` dan `products.unit` memakai `VARCHAR(32)`; keduanya wajib terisi dan tidak boleh berisi string kosong atau whitespace saja. Snapshot nama dan unit pada item transaksi memakai batas panjang yang sama. Validasi API dan constraint database harus konsisten.
- **[TERKONFIRMASI]** Delete produk adalah soft delete/arsip. Data tidak dihapus secara fisik. DELETE berhasil dengan `204 No Content`, termasuk untuk produk yang sudah memiliki riwayat transaksi; DELETE ulang pada produk yang sudah diarsipkan menghasilkan `404 Not Found`. Produk arsip tidak muncul di katalog POS dan tidak dapat digunakan pada transaksi baru.
- **[TERKONFIRMASI]** Produk arsip yang dikirim dalam transaksi baru menghasilkan `409 Conflict`; transaksi seluruhnya dibatalkan. Riwayat transaksi lama beserta snapshot item tetap tersimpan dan dapat dibaca.
- **[TERKONFIRMASI]** Harga transaksi berasal dari database backend. Item transaksi menyimpan snapshot nama produk, unit, harga satuan, quantity, dan subtotal.
- **[TERKONFIRMASI]** Mata uang harga adalah IDR (Rupiah). Harga selalu bilangan bulat tanpa pecahan rupiah; API memakai JSON integer dan PostgreSQL memakai `NUMERIC(15,0)` untuk `selling_price`, `unit_price_snapshot`, `subtotal`, dan `total_amount`. Nilai harus berada pada rentang 0–999999999999999; input desimal, negatif, atau melebihi kapasitas ditolak. Tidak ada pembulatan implisit.
- **[TERKONFIRMASI]** Kuantitas transaksi bilangan bulat positif; stok tidak boleh negatif. Transaksi dan pengurangan stok harus atomik.
- **[TERKONFIRMASI]** ID menggunakan UUID yang dibuat aplikasi Laravel dan diberikan eksplisit saat insert. Semua timestamp memakai `TIMESTAMPTZ` dan dikelola Laravel; `DEFAULT CURRENT_TIMESTAMP` menjadi fallback untuk timestamp yang sesuai. `updated_at` diperbarui Laravel pada perubahan record. Nilai bisnis wajib tidak mendapat default.
- **[TERKONFIRMASI]** Prefix API `/api/v1`. POS wajib menyertakan `Idempotency-Key` non-empty sepanjang 1–255 karakter. `UNIQUE(key)` berlaku global di seluruh Inventory Backend; satu key hanya untuk satu operasi transaksi. Key dan payload identik mengembalikan response tersimpan (sukses atau konflik bisnis); key yang sudah tercatat dengan payload berbeda menghasilkan `409 Conflict` Problem Details.
- **[TERKONFIRMASI]** Request JSON dikanonisasi menggunakan RFC 8785 JSON Canonicalization Scheme (JCS) secara konsisten pada request pertama dan setiap retry. Hasil JCS menjadi input SHA-256; hasil hash disimpan sebagai 64 karakter hexadecimal pada `request_hash CHAR(64)`. Hash sama menandakan payload identik untuk idempotency; hash berbeda dengan key yang sama menghasilkan `409 Conflict` Problem Details.
- **[TERKONFIRMASI]** Seluruh langkah transaksi penjualan—klaim Idempotency-Key, validasi produk/stok, pembacaan harga/nama/unit untuk snapshot, pengurangan stok, penyimpanan transaksi/item/response idempotency, dan insert outbox bila threshold terlintasi—berada dalam satu transaksi database. Produk yang terlibat dikunci dengan `SELECT FOR UPDATE` dalam urutan UUID konsisten; status arsip, stok, dan harga diperiksa di bawah lock. Stok tidak boleh negatif.
- **[TERKONFIRMASI]** Publikasi RabbitMQ dilakukan setelah transaksi database commit, tidak di dalam transaksi database.
- **[TERKONFIRMASI]** `POST /api/v1/transactions` mengembalikan `200 OK`, mengikuti P03. Error API menggunakan `application/problem+json` sesuai RFC 9457.
- **[TERKONFIRMASI]** Inventory Backend menerbitkan notifikasi stok minimum secara asinkron melalui RabbitMQ. Supplier Order Gateway menerima dan mencatat notifikasi; sistem tidak mengirim pesanan supplier otomatis.
- **[TERKONFIRMASI]** Dashboard membutuhkan ringkasan penjualan, produk terlaris, informasi keuangan, dan kebutuhan restock. Mekanisme integrasinya belum dipilih.
- **[TERKONFIRMASI]** Frontend POS/Dashboard bukan lingkup implementasi backend Checkpoint 1. Autentikasi belum dinilai di Checkpoint 1 menurut P07.

### 1.2 Baseline event

- **[TERKONFIRMASI]** Event `StockMinimumReached` pada exchange `inventory.events` bertipe `topic`, routing key `inventory.stock.minimum_reached`, queue `supplier-order.stock-minimum-reached`.
- **[TERKONFIRMASI]** Publisher: Inventory Backend. Consumer: Supplier Order Gateway.
- **[TERKONFIRMASI]** Trigger: transisi `stock_before > threshold` menjadi `stock_after <= threshold`. Setiap pesan membawa `eventId` unik.
- **[TERKONFIRMASI]** Event hanya notifikasi kondisi stok minimum, bukan purchase order.
- **[TERKONFIRMASI]** Untuk lingkup event yang direncanakan saat ini, `outbox_events.aggregate_id` mereferensikan `products.id` dengan `ON DELETE RESTRICT`. Event yang direncanakan saat ini hanya `StockMinimumReached` dan aggregate-nya produk. Jika kelak ada aggregate selain produk, desain outbox/FK harus ditinjau kembali.
- **[TERKONFIRMASI]** Inventory memakai transactional outbox dengan retry publish dan status dead-letter di database; Supplier Order Gateway mencatat event secara idempoten berdasarkan `eventId`. Topologi RabbitMQ DLQ dan kebijakan retry Gateway belum diputuskan (§6). Status dead-letter Inventory di database bukan RabbitMQ DLQ.
- **[TERKONFIRMASI]** Status outbox adalah `pending`, `publishing`, `published`, atau `dead_lettered`; klaim event memakai mekanisme lease untuk mendukung worker paralel.
- **[TERKONFIRMASI]** Outbox menggunakan `lease_token UUID NULL` dan `lease_expires_at TIMESTAMPTZ NULL`. Worker hanya boleh memperbarui event jika token klaimnya masih cocok; event `publishing` dengan lease kedaluwarsa dapat diklaim ulang. Lease berlaku 60 detik.
- **[TERKONFIRMASI]** Maksimum percobaan publish adalah 5 termasuk percobaan pertama. Retry memakai exponential backoff dengan basis 5 detik dan batas maksimum 15 menit. Setelah percobaan habis, event ditandai `dead_lettered` di database saja; integrasi/topologi DLQ broker belum dipilih.
- **[TERKONFIRMASI]** Retensi event belum diputuskan; tidak ada cleanup otomatis sebelum kebijakan retensi ditetapkan.
- **[TERKONFIRMASI]** Constraint untuk `products.selling_price`, `transaction_items.unit_price_snapshot`, `transaction_items.subtotal`, dan `transactions.total_amount` harus menolak nilai negatif dan PostgreSQL `NaN`. Validasi aplikasi tetap wajib dan tidak digantikan constraint database.
- **[TERKONFIRMASI]** Setiap `transaction_items.subtotal` harus sama dengan `quantity * unit_price_snapshot`; quantity harus integer positif, unit price dan subtotal tidak negatif serta bukan `NaN`. Overflow ditolak sebelum transaksi disimpan. `transactions.total_amount` dihitung dari seluruh subtotal dan diverifikasi di dalam transaksi database yang sama; CHECK satu baris tidak menjamin jumlah lintas-row.
- **[TERKONFIRMASI]** Satu `productId` hanya boleh muncul satu kali dalam satu transaksi. Request duplikat ditolak dengan `422 Problem Details` sebelum penyimpanan transaksi; `transaction_items` memiliki `UNIQUE (transaction_id, product_id)` sebagai constraint database.
- **[TERKONFIRMASI]** Idempotency untuk transaksi sukses menyimpan key, request hash, transaction ID, response status/body secara atomik bersama transaksi, item, pengurangan stok, dan outbox. Key/hash sama me-replay response tersimpan; key yang sudah memiliki record dengan hash berbeda menghasilkan `409 Problem Details`.
- **[TERKONFIRMASI]** JSON malformed dan kegagalan validasi request tidak membuat record idempotency. Konflik bisnis deterministik yang telah ditetapkan—stok tidak cukup dan produk arsip—menyimpan response `409` bersama key/hash tanpa transaction ID, agar retry dengan hash sama mengembalikan konflik yang sama. Kegagalan internal `500` me-rollback semua perubahan dan tidak disimpan sebagai hasil final. Tidak boleh tersimpan transaksi atau pengurangan stok parsial.
- **[TERKONFIRMASI]** Record sukses disimpan dalam transaksi penjualan; record konflik bisnis disimpan tanpa transaksi dan tanpa perubahan inventori. Keduanya memakai unique key global serta hash yang sama. Tidak dibuat status `processing` atau mekanisme claim terpisah.
- **[TERKONFIRMASI]** Response `400`, `404` produk tidak ditemukan, `422`, dan `500` tidak disimpan sebagai hasil idempotency. Key dari request tersebut tidak terikat permanen pada payload; retry dengan key yang sama dapat diproses kembali jika kondisi produk berubah. Konflik hash hanya dapat dipastikan jika key telah memiliki record committed.
- **[TERKONFIRMASI]** Tabel `stock_movements` tidak dibuat untuk Checkpoint 1. Restock dan penyesuaian stok manual berada di luar scope implementasi saat ini; kebutuhan ledger mutasi dapat dievaluasi pada pengembangan berikutnya.

### 1.3 Checkpoint 1

- **[TERKONFIRMASI]** P07 meminta `openapi.yaml` design-first yang lolos Spectral, kontrak operasi/skema/error Problem Details, kontrak event, sekurang-kurangnya satu layanan berjalan, feature test, README, serta demo request sukses/gagal dan event.
- **[TERKONFIRMASI]** P07 membatasi dokumen analisis menjadi 4–8 halaman. Dokumen kelompok saat ini 10 halaman dan perlu diringkas.
- **[TERKONFIRMASI]** P03 menyebut `200 OK` untuk response transaksi POS; baseline ini mempertahankannya.

## 2. Rekomendasi teknis umum

- **[REKOMENDASI TEKNIS]** JSON memakai camelCase; UUID sebagai string kanonis; kuantitas sebagai JSON integer; waktu sebagai RFC 3339/ISO 8601 dengan zona waktu.
- **[REKOMENDASI TEKNIS]** Status selain transaksi: GET `200`, POST produk `201 Created`, PUT/PATCH `200`, DELETE `204 No Content`. Ini konvensi implementasi yang disarankan, bukan keputusan pemilik.
- **[REKOMENDASI TEKNIS]** Gunakan `400` untuk JSON malformed, `404` untuk resource path yang tidak ada/produk yang sudah diarsipkan saat DELETE, `422` untuk payload gagal validasi, dan `409` untuk konflik state (stok tidak cukup, Idempotency-Key dengan payload berbeda, atau transaksi yang meminta produk arsip). Semua memakai Problem Details.
- **[REKOMENDASI TEKNIS]** Validasi stok awal dan threshold integer nonnegatif; transaksi memiliki minimal satu item dengan quantity integer positif. Untuk nama/unit, validasi API mengikuti constraint nonempty yang telah dikonfirmasi.
- **[REKOMENDASI TEKNIS]** Gunakan `FOR UPDATE SKIP LOCKED` untuk memilih event outbox yang akan diklaim. Token lease, expiry, pembaruan bersyarat pada token, dan reclaim lease kedaluwarsa telah dikonfirmasi; pola pemilihan paralel ini tetap rekomendasi implementasi.
- **[REKOMENDASI TEKNIS]** `stockQuantity` read-only pada update katalog. Perubahan stok dilakukan oleh transaksi, penerimaan/restock, atau penyesuaian inventori yang tercatat; alur adjustment masih pending.

## 3. Kontrak REST baseline

Semua request/response sukses menggunakan `application/json`. `openapi.yaml` adalah kontrak terkini untuk operasi yang sudah diimplementasikan; tabel di bawah mempertahankan baseline dan rekomendasi teknis, dan tidak berarti semua operasi opsional di tabel tersedia pada API.

| Metode dan path | Fungsi | Response rekomendasi |
|---|---|---|
| `GET /api/v1/products` | Daftar produk | `200`, `{ "data": [Product, ...] }` |
| `GET /api/v1/products/{productId}` | Detail produk | `200`; `404` Problem Details jika tidak ditemukan |
| `POST /api/v1/products` | Membuat produk | `201`, `{ "data": Product }`, header `Location` |
| `PUT /api/v1/products/{productId}` | Mengganti semua field yang dapat diedit | `200`, `{ "data": Product }` |
| `PATCH /api/v1/products/{productId}` | Update parsial opsional; belum tersedia pada API saat ini | Pending keputusan kebutuhan; bukan endpoint OpenAPI saat ini |
| `DELETE /api/v1/products/{productId}` | Mengarsipkan produk (soft delete) | `204` termasuk produk ber-riwayat; `404` jika tidak ada atau sudah diarsipkan |
| `POST /api/v1/transactions` | Memproses transaksi dan mengurangi stok | `200`, `{ "data": Transaction }` |

Pagination/filter produk belum ditetapkan. API saat ini memakai `PUT` untuk update produk; dukungan PATCH belum diputuskan dan tidak diimplementasikan.

### 3.1 Tipe field API

| Field | Tipe JSON | Catatan |
|---|---|---|
| `id`, `productId`, `transactionId`, `eventId` | string UUID | Identitas resource/event |
| `name`, `unit`, `productName`, `eventType`, `type`, `title`, `detail`, `code` | string | `name`/`productName` maksimal 160 karakter dan nonempty; `unit` maksimal 32 karakter dan nonempty; batas ini juga berlaku pada snapshot transaksi |
| `Idempotency-Key` (HTTP header) | string | Wajib, tidak kosong, maksimum 255 karakter; UNIQUE global di Inventory Backend; retensi masih pending |
| `sellingPrice`, `unitPrice`, `subtotal`, `totalAmount` | integer | Rupiah bulat; rentang 0–999999999999999; kolom PostgreSQL `NUMERIC(15,0)` |
| `stockQuantity`, `minimumStock`, `quantity`, `stockBefore`, `stockAfter`, `minimumThreshold` | integer | Kuantitas bulat; quantity transaksi positif, stok/threshold nonnegatif |
| `createdAt`, `updatedAt`, `occurredAt` | string date-time | RFC 3339/ISO 8601 dengan zona waktu |
| `data` | object atau array | Object untuk resource tunggal; array Product untuk daftar |
| `items` | array of object | Transaction request/response item; setiap elemennya memiliki field bertipe pada contoh §3.3 |
| `errors` | array of object | Problem Details validation errors; objek memiliki `pointer`, `code`, `detail` string |
| `product`, `stock` | object | Objek bertingkat pada event; field-nya ditetapkan di §5.2 |
| `status` | integer | Kode status HTTP pada Problem Details |
| `type`, `instance`, `pointer` | string URI/reference | `pointer` adalah ekstensi untuk lokasi field |

### 3.2 CRUD produk: contoh kontrak

Field Product yang direkomendasikan: `id`, `name`, `unit`, `sellingPrice`, `stockQuantity`, `minimumStock`, `createdAt`, `updatedAt`. SKU belum dimasukkan karena pemilik belum mengonfirmasi kebutuhan SKU.

**Create — `POST /api/v1/products` request**

```json
{
  "name": "Air Mineral 600 ml",
  "unit": "botol",
  "sellingPrice": 3500,
  "stockQuantity": 24,
  "minimumStock": 6
}
```

**Create/read/update response**

```json
{
  "data": {
    "id": "b2e0b1f4-f8c1-4d9c-9ee1-44fdd3c88f01",
    "name": "Air Mineral 600 ml",
    "unit": "botol",
    "sellingPrice": 3500,
    "stockQuantity": 24,
    "minimumStock": 6,
    "createdAt": "2026-11-01T08:30:00Z",
    "updatedAt": "2026-11-01T08:30:00Z"
  }
}
```

GET daftar memakai `data` berupa array; GET detail memakai satu objek Product. PUT menerima field yang dapat diedit (`name`, `unit`, `sellingPrice`, `minimumStock`); PATCH hanya merupakan kemungkinan update parsial dan belum tersedia. ID, timestamp, dan stok read-only pada update menurut rekomendasi ini.

**Update — contoh request PUT/PATCH**

```json
{
  "name": "Air Mineral 600 ml",
  "unit": "botol",
  "sellingPrice": 4000,
  "minimumStock": 8
}
```

DELETE menghasilkan `204 No Content` tanpa body dan mengarsipkan produk tanpa menghapus row secara fisik. Produk arsip dikecualikan dari katalog POS dan ditolak untuk transaksi baru. Pengulangan DELETE pada produk yang sudah diarsipkan menghasilkan `404 Not Found` Problem Details.

### 3.3 Transaksi: contoh kontrak

POS wajib mengirim `Idempotency-Key` non-empty sepanjang 1–255 karakter; key unik secara global di Inventory Backend. Harga tidak diterima dari POS; backend mengambil harga produk dan menyimpan snapshot nama, unit, harga, quantity, dan subtotal di `transaction_items`. `quantity` adalah integer positif. Key, hash request, transaksi, dan response sukses disimpan dalam transaksi database yang sama dengan penjualan. Request JSON dikanonisasi memakai RFC 8785 JCS, lalu di-hash dengan SHA-256 menjadi 64 karakter hexadecimal; aturan ini berlaku konsisten untuk request awal dan retry.

**[REKOMENDASI TEKNIS] Penanganan race Idempotency-Key:** unique global menjadi arbiter klaim bersamaan. Untuk sukses, simpan row key/response dalam transaksi yang sama dengan perubahan penjualan. Untuk konflik bisnis deterministik, simpan row key/hash/409 tanpa transaction ID dan tanpa perubahan stok/item/transaksi/outbox. Jika insert key yang sama kalah, rollback request yang kalah, baca row pemenang, bandingkan hash, lalu replay status/body tersimpan atau kembalikan 409 untuk hash berbeda. Tidak ada status `processing` atau claim terpisah. Kegagalan validasi dan `500` yang tidak committed tidak mengikat key.

**`POST /api/v1/transactions` request**

```json
{
  "items": [
    {
      "productId": "b2e0b1f4-f8c1-4d9c-9ee1-44fdd3c88f01",
      "quantity": 2
    }
  ]
}
```

**`200 OK` response**

```json
{
  "data": {
    "id": "c2eafc53-3f5e-41e0-9a15-479a6cda73cc",
    "transactionAt": "2026-11-01T08:32:00Z",
    "createdAt": "2026-11-01T08:32:00Z",
    "updatedAt": "2026-11-01T08:32:00Z",
    "items": [
      {
        "productId": "b2e0b1f4-f8c1-4d9c-9ee1-44fdd3c88f01",
        "productName": "Air Mineral 600 ml",
        "quantity": 2,
        "unit": "botol",
        "unitPrice": 3500,
        "subtotal": 7000
      }
    ],
    "totalAmount": 7000
  }
}
```

**[TERKONFIRMASI]** Simpan key unik global, request hash, dan response sukses bersama transaksi; response `409` stok tidak cukup atau produk arsip juga disimpan tanpa transaction ID. Retry dengan key/payload identik mengembalikan status/body yang tersimpan. Key sama dengan payload berbeda menghasilkan `409 Conflict` Problem Details. Response `400`, `404` produk tidak ditemukan, `422`, dan `500` tidak disimpan. Retensi key masih **PENDING**.

### 3.4 Problem Details: contoh `422`

```json
{
  "type": "https://example.invalid/problems/validation-error",
  "title": "Input tidak valid",
  "status": 422,
  "detail": "Satu atau lebih field tidak memenuhi ketentuan.",
  "instance": "/api/v1/transactions",
  "errors": [
    {
      "pointer": "/items/0/quantity",
      "code": "positive_integer_required",
      "detail": "Quantity harus bilangan bulat positif."
    }
  ]
}
```

`type`, `title`, `status`, `detail`, dan `instance` mengikuti RFC 9457. `errors` adalah ekstensi rekomendasi berupa array objek; `pointer`, `code`, `detail` bertipe string. Header: `Content-Type: application/problem+json`. URI `example.invalid` hanya placeholder dokumentasi dan harus diganti URI stabil sebelum kontrak dirilis. Jangan memasukkan stack trace, SQL, secret, atau data internal ke `detail`.

## 4. Rancangan skema PostgreSQL

Ini rancangan konseptual untuk tabel baseline, bukan migration. Harga menggunakan PostgreSQL `NUMERIC(15,0)` dan JSON integer. Nilai maksimum kolom adalah `999999999999999`; validasi API harus menolak pecahan dan nilai di luar rentang sebelum assignment ke kolom. PostgreSQL dapat membulatkan input pecahan ke scale kolom, sehingga constraint kolom saja tidak cukup untuk menjamin penolakan tanpa pembulatan. Mata uang IDR serta ketiadaan pecahan rupiah telah dikonfirmasi.

### 4.1 `products`

| Kolom | Tipe PostgreSQL | Constraint/relation |
|---|---|---|
| `id` | `UUID` | Primary key; dibuat aplikasi Laravel dan diberikan saat insert; tanpa default DB |
| `name` | `VARCHAR(160)` | `NOT NULL`, check bukan string kosong/whitespace saja |
| `unit` | `VARCHAR(32)` | `NOT NULL`, check bukan string kosong/whitespace saja |
| `selling_price` | `NUMERIC(15,0)` | `NOT NULL`, CHECK setara dengan `selling_price <> 'NaN'::numeric AND selling_price >= 0`; IDR bulat, maksimum `999999999999999` |
| `stock_quantity` | `INTEGER` | `NOT NULL`, `CHECK (stock_quantity >= 0)` |
| `minimum_stock` | `INTEGER` | `NOT NULL`, `CHECK (minimum_stock >= 0)` |
| `created_at`, `updated_at` | `TIMESTAMPTZ` | `NOT NULL DEFAULT CURRENT_TIMESTAMP` sebagai fallback; dikelola Laravel dan `updated_at` diperbarui Laravel |
| `deleted_at` | `TIMESTAMPTZ` | nullable; waktu arsip (soft delete), tanpa default |

### 4.2 `transactions`

| Kolom | Tipe PostgreSQL | Constraint/relation |
|---|---|---|
| `id` | `UUID` | Primary key; dibuat aplikasi Laravel dan diberikan saat insert; tanpa default DB |
| `total_amount` | `NUMERIC(15,0)` | `NOT NULL`, CHECK setara dengan `total_amount <> 'NaN'::numeric AND total_amount >= 0`; IDR bulat, dihitung backend dari subtotal item; jumlah dan kapasitas diverifikasi sebelum simpan; tanpa default |
| `transaction_at` | `TIMESTAMPTZ` | `NOT NULL DEFAULT CURRENT_TIMESTAMP` sebagai fallback; waktu penjualan dikelola Laravel |
| `created_at` | `TIMESTAMPTZ` | `NOT NULL DEFAULT CURRENT_TIMESTAMP` sebagai fallback; dikelola Laravel |
| `updated_at` | `TIMESTAMPTZ` | `NOT NULL DEFAULT CURRENT_TIMESTAMP` sebagai fallback; diperbarui Laravel |

### 4.3 `transaction_items`

| Kolom | Tipe PostgreSQL | Constraint/relation |
|---|---|---|
| `id` | `UUID` | Primary key; dibuat aplikasi Laravel dan diberikan saat insert; tanpa default DB |
| `transaction_id` | `UUID` | `NOT NULL`, FK → `transactions.id`, rekomendasi `ON DELETE RESTRICT` |
| `product_id` | `UUID` | `NOT NULL`, FK → `products.id`, `ON DELETE RESTRICT` |
| `product_name_snapshot` | `VARCHAR(160)` | `NOT NULL`, check bukan string kosong/whitespace saja; nama produk saat penjualan |
| `unit_snapshot` | `VARCHAR(32)` | `NOT NULL`, check bukan string kosong/whitespace saja; satuan produk saat penjualan |
| `quantity` | `INTEGER` | `NOT NULL`, `CHECK (quantity > 0)` |
| `unit_price_snapshot` | `NUMERIC(15,0)` | `NOT NULL`, CHECK setara dengan `unit_price_snapshot <> 'NaN'::numeric AND unit_price_snapshot >= 0`; snapshot harga IDR bulat backend |
| `subtotal` | `NUMERIC(15,0)` | `NOT NULL`, CHECK setara dengan `subtotal <> 'NaN'::numeric AND subtotal >= 0`; CHECK baris `subtotal = quantity * unit_price_snapshot`; tolak overflow sebelum transaksi disimpan |

Relasi: satu transaction memiliki banyak transaction_items; satu product dapat direferensikan banyak transaction_items. Tambahkan `UNIQUE (transaction_id, product_id)` agar satu produk hanya muncul sekali pada setiap transaksi. API menolak duplikasi sebelum penyimpanan. `transactions.total_amount` adalah jumlah `transaction_items.subtotal`, dihitung dan diverifikasi backend di dalam transaksi database yang sama. CHECK row-level subtotal tidak dapat menjamin konsistensi agregat total lintas beberapa row.

### 4.4 `idempotency_keys`

| Kolom | Tipe PostgreSQL | Constraint/relation |
|---|---|---|
| `id` | `UUID` | Primary key; dibuat aplikasi Laravel dan diberikan saat insert; tanpa default DB |
| `key` | `VARCHAR(255)` | `NOT NULL`, `UNIQUE` secara global di Inventory Backend; dipetakan dari header `Idempotency-Key` (1–255 karakter) |
| `request_hash` | `CHAR(64)` | `NOT NULL`, check format `^[0-9A-Fa-f]{64}$`; SHA-256 atas request JSON yang telah dikanonisasi dengan RFC 8785 JCS; sama berarti payload identik untuk idempotency |
| `transaction_id` | `UUID` | nullable, `UNIQUE`, FK → `transactions.id`, `ON DELETE RESTRICT`; terisi untuk hasil sukses dan NULL untuk konflik bisnis tersimpan |
| `response_status` | `SMALLINT` | `NOT NULL`, CHECK `(response_status = 200 AND transaction_id IS NOT NULL) OR (response_status = 409 AND transaction_id IS NULL)` |
| `response_body` | `JSONB` | `NOT NULL`; response yang dikembalikan pada retry identik |
| `created_at` | `TIMESTAMPTZ` | `NOT NULL DEFAULT CURRENT_TIMESTAMP` sebagai fallback; dikelola Laravel |
| `expires_at` | `TIMESTAMPTZ` | nullable sampai kebijakan retensi diputuskan |

`response_status` membedakan hasil yang disimpan: `200` untuk sukses dengan transaction ID dan `409` untuk konflik bisnis deterministik tersimpan tanpa transaction ID. CHECK membatasi record tersimpan pada pasangan hasil tersebut. Response `400`, `404` produk tidak ditemukan, `422`, dan `500` tidak disimpan. Key dari request `404` tidak terikat permanen; retry dapat diproses kembali jika kondisi produk berubah. Konflik `409` karena key telah digunakan dengan hash berbeda mengembalikan Problem Details tanpa mengganti record yang sudah ada.

### 4.5 `outbox_events`

| Kolom | Tipe PostgreSQL | Constraint/relation |
|---|---|---|
| `event_id` | `UUID` | Primary key; dibuat aplikasi Laravel dan diberikan saat insert; sama dengan payload `eventId`; tanpa default DB |
| `event_type` | `VARCHAR(120)` | `NOT NULL` |
| `exchange_name` | `VARCHAR(160)` | `NOT NULL` |
| `routing_key` | `VARCHAR(200)` | `NOT NULL` |
| `aggregate_id` | `UUID` | `NOT NULL`, FK → `products.id ON DELETE RESTRICT` untuk event saat ini (`StockMinimumReached`); tinjau ulang jika ada aggregate lain |
| `payload` | `JSONB` | `NOT NULL` |
| `occurred_at`, `created_at` | `TIMESTAMPTZ` | `NOT NULL DEFAULT CURRENT_TIMESTAMP` sebagai fallback; dikelola Laravel |
| `status` | `VARCHAR(20)` | `NOT NULL`, `CHECK (status IN ('pending','publishing','published','dead_lettered'))`; vocabulary dikonfirmasi, default `pending` adalah usulan teknis |
| `lease_token` | `UUID` | nullable; token klaim worker; worker hanya memperbarui jika token masih cocok |
| `lease_expires_at` | `TIMESTAMPTZ` | nullable; expiry lease; event `publishing` dengan lease kedaluwarsa dapat diklaim ulang |
| `published_at` | `TIMESTAMPTZ` | nullable; waktu publisher confirm; status `published` berarti publikasi terkonfirmasi |
| `dead_lettered_at` | `TIMESTAMPTZ` | nullable; waktu event ditandai dead-lettered (usulan teknis) |
| `attempt_count` | `INTEGER` | `NOT NULL DEFAULT 0`, `CHECK (attempt_count >= 0)` |
| `next_attempt_at` | `TIMESTAMPTZ` | nullable |
| `last_error` | `TEXT` | nullable; jangan menyimpan secret |

Relasi utama: `transactions.id` → `transaction_items.transaction_id` satu-ke-banyak; `products.id` → `transaction_items.product_id` satu-ke-banyak; `transactions.id` → `idempotency_keys.transaction_id` satu-ke-satu untuk hasil sukses (NULL untuk konflik bisnis tersimpan). `transaction_items.product_id` memakai `ON DELETE RESTRICT`. Operasi DELETE aplikasi hanya mengisi `products.deleted_at`; ia tidak menjalankan SQL DELETE, sehingga soft delete tetap berfungsi dan riwayat transaksi tetap merujuk row produk. FK transaksi memakai `RESTRICT`. `outbox_events.aggregate_id` mereferensikan `products.id` dengan `ON DELETE RESTRICT` untuk scope event saat ini. CHECK stok/quantity melindungi constraint dasar; aplikasi tetap perlu penguncian/conditional update untuk mencegah oversell saat transaksi bersamaan.

UUID untuk seluruh PK dibuat eksplisit oleh aplikasi Laravel; tidak ada default UUID database. Timestamp memakai `TIMESTAMPTZ`, dikelola Laravel, dengan `DEFAULT CURRENT_TIMESTAMP` hanya sebagai fallback yang sesuai. Nilai bisnis seperti total, subtotal, quantity, stok, harga, response, dan key tidak diberi default. `updated_at` diperbarui Laravel.

**[REKOMENDASI TEKNIS] Indeks:** PK dan UNIQUE constraints menyediakan indeksnya sendiri. Tambahkan indeks pada `transaction_items.transaction_id` dan `transaction_items.product_id` untuk lookup item dan pemeriksaan relasi. Indeks polling outbox mengikuti query klaim final. Indeks katalog/laporan belum ditetapkan karena bergantung query final; jangan menganggapnya keputusan final.

### 4.6 Pemetaan API JSON ke database

| Properti API JSON / header | Kolom PostgreSQL | Catatan |
|---|---|---|
| `Product.id`, `Product.name`, `Product.unit` | `products.id`, `products.name`, `products.unit` | UUID dibuat aplikasi; nama/unit dibatasi panjang dan nonempty |
| `Product.sellingPrice` | `products.selling_price` | JSON integer IDR ↔ `NUMERIC(15,0)` |
| `Product.stockQuantity` | `products.stock_quantity` | Integer |
| `Product.minimumStock` | `products.minimum_stock` | Integer |
| `deletedAt` internal | `products.deleted_at` | Diisi backend saat soft delete; tidak diterima/dikirim melalui request produk |
| `Transaction.totalAmount` | `transactions.total_amount` | Jumlah integer seluruh subtotal; `NUMERIC(15,0)` |
| `Transaction.transactionAt` | `transactions.transaction_at` | Timestamp transaksi |
| `Transaction.createdAt`, `Transaction.updatedAt` | `transactions.created_at`, `transactions.updated_at` | Timestamp record |
| `Transaction.items[].productName` | `transaction_items.product_name_snapshot` | Snapshot nama produk |
| `Transaction.items[].unit` | `transaction_items.unit_snapshot` | Snapshot unit |
| `Transaction.items[].quantity` | `transaction_items.quantity` | Integer positif |
| `Transaction.items[].unitPrice` | `transaction_items.unit_price_snapshot` | JSON integer IDR ↔ `NUMERIC(15,0)` |
| `Transaction.items[].subtotal` | `transaction_items.subtotal` | Perkalian integer `quantity * unit_price_snapshot`; `NUMERIC(15,0)`, tolak overflow tanpa pembulatan |
| `Transaction.id`, `Transaction.items[].productId` | `transactions.id`, `transaction_items.product_id` | UUID; item FK `RESTRICT` ke produk |
| Header `Idempotency-Key` | `idempotency_keys.key` | Unik, 1–255 karakter |
| Request JSON transaksi | `idempotency_keys.request_hash` | RFC 8785 JCS lalu SHA-256; 64 karakter hexadecimal |
| Response transaksi sukses/konflik | `idempotency_keys.response_status`, `response_body`, `transaction_id` | `200` dengan transaction ID; `409` konflik bisnis dengan transaction ID NULL |

## 5. Kontrak event RabbitMQ

### 5.1 Binding dan semantik

| Properti | Baseline |
|---|---|
| Event | `StockMinimumReached` |
| Exchange | `inventory.events` |
| Exchange type | `topic` |
| Routing key | `inventory.stock.minimum_reached` |
| Queue | `supplier-order.stock-minimum-reached` |
| Publisher | Inventory Backend |
| Consumer | Supplier Order Gateway |
| Trigger | `stock_before > minimum_threshold` dan `stock_after <= minimum_threshold` |
| Efek | Gateway mencatat notifikasi kebutuhan restock; tidak membuat/mengirim order supplier |

### 5.2 Payload JSON dan tipe field

```json
{
  "eventId": "8d52dc2e-1e3f-4615-9464-41c928b25737",
  "eventType": "StockMinimumReached",
  "occurredAt": "2026-11-01T08:35:00Z",
  "aggregateId": "b2e0b1f4-f8c1-4d9c-9ee1-44fdd3c88f01",
  "product": {
    "id": "b2e0b1f4-f8c1-4d9c-9ee1-44fdd3c88f01",
    "name": "Air Mineral 600 ml",
    "unit": "botol"
  },
  "stock": {
    "stockBefore": 7,
    "stockAfter": 6,
    "minimumThreshold": 6
  }
}
```

| Field | Tipe | Constraint/makna |
|---|---|---|
| `eventId` | string UUID | Unik per event; juga message ID |
| `eventType` | string literal | `StockMinimumReached` |
| `occurredAt` | string date-time RFC 3339 | Waktu transisi terdeteksi |
| `aggregateId` | string UUID | ID produk aggregate yang mengalami transisi |
| `product.id` | string UUID | ID produk Inventory Backend |
| `product.name`, `product.unit` | string | Nama dan unit saat event dibuat |
| `stock.stockBefore`, `stock.stockAfter`, `stock.minimumThreshold` | integer | Kuantitas stok/threshold; nonnegatif dan memenuhi transisi baseline |

Harga tidak disertakan karena pesan bukan order. Seluruh field pada payload di atas bertipe eksplisit dan konsisten dengan stok integer pada database.

### 5.3 Outbox, retry, DLQ, dan deduplikasi

- **[TERKONFIRMASI] Outbox atomik:** row outbox dimasukkan dalam transaksi database yang sama dengan transaksi penjualan dan pengurangan stok. Kegagalan satu langkah membatalkan semua perubahan.
- **[TERKONFIRMASI] Status dan lease:** status outbox adalah `pending`, `publishing`, `published`, atau `dead_lettered`; kolom lease `lease_token UUID NULL` dan `lease_expires_at TIMESTAMPTZ NULL` mendukung klaim worker paralel. Worker hanya memperbarui event jika token klaimnya masih cocok; event `publishing` dengan lease kedaluwarsa dapat diklaim ulang.
- **[TERKONFIRMASI — DIIMPLEMENTASIKAN] Relay dan publisher Inventory:** pilih event eligible dengan `FOR UPDATE SKIP LOCKED`, ubah status menjadi `publishing`, isi token/expiry lease, lalu commit klaim sebelum publish jaringan. Publisher memakai persistent message, `mandatory=true`, dan publisher confirm; relay mengubah status ke `published` hanya jika token lease masih cocok. Maksimum percobaan publish adalah 5 termasuk percobaan pertama, dengan exponential backoff berbasis 5 detik dan batas maksimum 15 menit. Setelah batas percobaan, event ditandai `dead_lettered` di database Inventory. Ini bukan RabbitMQ DLQ. Crash setelah broker menerima pesan tetapi sebelum status database diperbarui dapat menyebabkan duplikat; consumer harus idempoten.
- **[TERKONFIRMASI] Indeks relay:** indeks parsial mendukung klaim event `pending` berdasarkan `next_attempt_at` dan reclaim lease `publishing` berdasarkan `lease_expires_at`.
- **[TERKONFIRMASI — DIIMPLEMENTASIKAN] Publisher RabbitMQ:** Inventory menerbitkan `StockMinimumReached` ke exchange topic durable `inventory.events`, routing key `inventory.stock.minimum_reached`, menggunakan persistent message, `mandatory=true`, dan publisher confirm.
- **[TERKONFIRMASI — DIIMPLEMENTASIKAN] Ledger dan deduplikasi Supplier Order Gateway:** tabel `stock_minimum_notifications` menyimpan notifikasi pada database Gateway dengan `event_id` UUID sebagai primary key. Unique key tersebut mencegah pencatatan ganda. Consumer memvalidasi payload dan kecocokan message ID; event baru dicatat dalam transaksi database sebelum ACK. Redelivery dengan event ID yang telah tersimpan tidak membuat baris kedua dan di-ACK setelah record sebelumnya ditemukan.
- **[TERKONFIRMASI — DIIMPLEMENTASIKAN] Consumer Gateway:** queue durable `supplier-order.stock-minimum-reached` diikat ke exchange dan routing key di atas; consumer membatasi prefetch menjadi satu. Gateway hanya mencatat notifikasi stok minimum dan tidak membuat atau mengirim pesanan supplier.
- **[PENDING]** Topologi RabbitMQ DLQ, kebijakan retry untuk pesan Gateway yang gagal/invalid, retensi event dan notifikasi, alert, prosedur replay, serta pemilik operasional.

## 6. Keputusan dan pekerjaan yang masih pending

1. **Produk nonaktif selain arsip:** apakah status aktif/nonaktif tambahan diperlukan; produk arsip sendiri sudah tidak tampil di katalog POS dan tidak dapat dipakai dalam transaksi.
2. **Tampilan produk arsip untuk administrasi:** apakah GET detail/daftar admin dapat menampilkan arsip atau perlu filter/endpoint khusus. Katalog POS tetap mengecualikannya.
3. **Dashboard:** integrasi melalui REST/reporting API atau read model/event, pemilik proyeksi, dan target kesegaran data.
4. **Laba-rugi:** sumber harga pokok, biaya operasional, serta perlakuan diskon/pajak. Harga jual saja tidak cukup untuk laporan laba bersih.
5. **Operasional event:** topologi RabbitMQ DLQ, kebijakan retry Gateway untuk pesan gagal/invalid, retensi, alert, prosedur replay, dan pemilik operasional. Retry relay Inventory (maksimum percobaan, backoff, dan lease) serta status dead-letter di database sudah diimplementasikan; status tersebut bukan RabbitMQ DLQ.
6. **Katalog:** kebutuhan SKU/keunikan, vocab unit yang diperbolehkan, pagination/filter, serta indeks katalog/laporan yang bergantung query final. Panjang nama/unit dan kebijakan item duplikat sudah dikonfirmasi.
7. **Penyesuaian stok:** proses penerimaan/restock dan koreksi serta aktor tetap belum ditetapkan. Tidak ada tabel `stock_movements` pada Checkpoint 1; kebutuhan ledger dievaluasi kemudian.
8. **Idempotency-Key:** masa simpan masih pending. Response `400`, `404` produk tidak ditemukan, `422`, dan `500` tidak disimpan dan tidak mengikat key secara permanen; response `409` untuk stok tidak cukup dan produk arsip disimpan. Scope UNIQUE global, panjang 1–255 karakter, RFC 8785 JCS, dan SHA-256 sudah ditetapkan.
9. **Operasi tambahan:** endpoint laporan, konfigurasi threshold, dan status notifikasi restock.
10. **Supplier Order Gateway:** masa simpan notifikasi dan status tindak lanjut. Database ledger serta deduplikasi berdasarkan event ID sudah diimplementasikan.
11. **Histori transaksi:** apakah diperlukan endpoint API untuk membaca histori; data historis dan snapshot tetap disimpan.
12. **Autentikasi/otorisasi:** tidak dinilai pada Checkpoint 1, tetapi perlu diputuskan sebelum penggunaan operasional.
13. **Dokumen analisis:** ringkas dari 10 halaman ke batas P07 4–8 halaman.

## 7. Status implementasi dan pekerjaan berikutnya

### 7.1 Sudah diimplementasikan

- OpenAPI design-first untuk endpoint produk dan transaksi, skema request/response, status, serta Problem Details; validasi Spectral terakhir mencatat 0 error dan 1 warning metadata `info.contact`.
- Migration PostgreSQL Inventory dan Supplier Order Gateway, Product CRUD, Transaction API, validasi, transaksi atomik, idempotency, dan feature/unit tests.
- Transactional outbox Inventory, relay manual, lease, retry, publisher RabbitMQ dengan confirm dan mandatory routing, serta status dead-letter di database.
- Consumer Supplier Order Gateway, validasi event, pencatatan notification ledger dan deduplikasi berdasarkan `eventId`; ACK dilakukan setelah pencatatan database berhasil.
- Integrasi Gateway telah diuji pada Stage 8.1 menggunakan broker demo terisolasi, termasuk event valid, duplicate delivery, dan event invalid.

### 7.2 Tersisa

1. Putuskan hal-hal bisnis/operasional yang masih pending pada §6; dokumentasi ini tidak menetapkan keputusan baru.
2. Sepakati topologi RabbitMQ DLQ, kebijakan retry Gateway, retensi, alert, replay, serta penanggung jawab operasional.
3. Tentukan integrasi Dashboard dan kebutuhan laporan sebelum merancang API atau read model terkait.
4. Lengkapi pembagian tugas anggota berdasarkan kontribusi dan riwayat commit yang terverifikasi.
5. Selaraskan dokumen analisis kelompok dengan batas 4–8 halaman P07 dan siapkan demo dengan broker/database terisolasi. Compose Gateway saat ini menggunakan shared network `warung-pak-hendy_sail`; jangan menganggap konfigurasi tersebut sebagai setup demo terisolasi.
6. Jalankan kembali test suite yang relevan pada database/broker khusus test; jangan mengaktifkan integration test terhadap RabbitMQ runtime utama.

## 8. Referensi

- `references/CheckPoint1_Proyek Akhir_Kel_67.pdf`: konteks, empat komponen, alur sinkron/asinkron, risiko, dan Dashboard (hlm. 2–10).
- `references/P03 - Lembar Kerja Tugas 3 - Memilih Gaya Integrasi(1).pdf`: pasangan integrasi, REST/RPI, messaging, dan response `200 OK` transaksi (hlm. 2–4).
- `references/P07 - Panduan Checkpoint 1 Proyek Akhir.docx`: analisis 4–8 halaman, OpenAPI design-first/Spectral, Problem Details, event contract, feature test, README, dan demo (§§1–3; dokumen Word tidak memiliki nomor halaman stabil).
