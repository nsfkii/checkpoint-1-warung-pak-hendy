# Warung Pak Hendy

Sistem terdiri dari dua aplikasi Laravel yang berkomunikasi melalui RabbitMQ:

| Aplikasi | Tanggung jawab | Database |
|---|---|---|
| Inventory Backend (root repository) | Product API, Transaction API, idempotency, transactional outbox, dan publisher | PostgreSQL Inventory (`laravel`) |
| Supplier Order Gateway (`supplier-order-gateway/`) | Consumer `StockMinimumReached` dan pencatatan notifikasi stok minimum; tidak membuat pesanan supplier | PostgreSQL Gateway (`supplier_order_gateway`) |

Kedua aplikasi menggunakan RabbitMQ yang sama. Inventory menerbitkan ke exchange topic `inventory.events` dengan routing key `inventory.stock.minimum_reached`; Gateway menerima dari queue `supplier-order.stock-minimum-reached`. Setiap aplikasi hanya terhubung ke database miliknya sendiri. Pengiriman outbox bersifat at-least-once; Gateway mendeduplikasi menggunakan `eventId`.

Dashboard Analytics & Laporan Keuangan masih merupakan komponen konseptual; mekanisme integrasi dan implementasinya belum diputuskan.

## Teknologi

- PHP 8.5 dan Laravel 13.
- PostgreSQL 18 untuk Inventory Backend dan database Gateway yang terpisah.
- RabbitMQ 4.4 untuk event asinkron.
- Docker Compose v2 untuk menjalankan service lokal.
- OpenAPI 3.1 untuk kontrak HTTP.

## Prasyarat

- Docker dan Docker Compose v2.
- PHP 8.5 dan Composer bila dependency perlu dipasang di host; Node.js/npm hanya diperlukan bila aset frontend perlu dibangun.
- Port host yang tersedia: `8000` (Inventory API), `5432` (PostgreSQL Inventory), `5672` (RabbitMQ), dan `15672` (RabbitMQ Management).

Gateway PostgreSQL tidak mempublikasikan port ke host. Database pengujian Gateway berjalan terpisah di `tmpfs`.

## Persiapan environment

Di root repository:

```bash
cp .env.example .env
composer install
./vendor/bin/sail artisan key:generate
```

Isi `.env` dengan kredensial lokal yang unik untuk `DB_PASSWORD`, `RABBITMQ_USER`, `RABBITMQ_PASSWORD`, dan `SUPPLIER_GATEWAY_DB_PASSWORD`. Jangan memakai contoh password atau memasukkan kredensial ke source control. `APP_PORT=8000` menjaga URL Inventory sesuai `APP_URL`.

Variabel Compose `SUPPLIER_GATEWAY_DB_PASSWORD`, `RABBITMQ_USER`, dan `RABBITMQ_PASSWORD` wajib diisi dengan nilai lokal yang sesuai sebelum menjalankan Compose Gateway. Nilai tersebut dibaca dari root `.env` melalui `--env-file .env`; keberadaan `supplier-order-gateway/.env` tidak menggantikan kebutuhan interpolasi Compose. `RABBITMQ_VHOST` opsional dan default-nya `/`. Jangan menyalin kredensial nyata ke dokumentasi atau source control.

Untuk konfigurasi key Gateway, siapkan environment aplikasinya:

```bash
cp supplier-order-gateway/.env.example supplier-order-gateway/.env
cd supplier-order-gateway
composer install
php artisan key:generate
cd ..
```

Gateway Compose memuat `supplier-order-gateway/.env` secara opsional untuk konfigurasi aplikasi seperti `APP_KEY`, tetapi kredensial runtime database dan RabbitMQ diambil dari `.env` root agar kedua aplikasi menggunakan broker yang sama.

> PostgreSQL hanya membaca `POSTGRES_PASSWORD` ketika volume database pertama kali dibuat. Mengubah nilai environment tidak otomatis mengganti password di volume yang sudah ada. Jangan menghapus volume; lakukan rotasi password secara terencana dan selaraskan konfigurasi setelahnya.

## Menjalankan kedua aplikasi

Jalankan Inventory Backend, PostgreSQL Inventory, dan RabbitMQ:

```bash
./vendor/bin/sail up -d
```

RabbitMQ adalah service pada Compose Inventory; Compose Gateway tidak menjalankan broker sendiri. Pastikan RabbitMQ healthy sebelum menghubungkan Gateway. Periksa status:

```bash
./vendor/bin/sail ps
```

Compose Gateway bergabung ke network bersama `warung-pak-hendy_sail` dan menggunakan RabbitMQ Inventory melalui service `rabbitmq`. Compose Gateway tidak menjalankan database Inventory maupun RabbitMQ sendiri. Database Gateway persisten dan terpisah.

Urutan aman untuk menyiapkan Gateway adalah mulai hanya database-nya, jalankan migrasi, lalu mulai service consumer:

```bash
docker compose \
  -f supplier-order-gateway/compose.yaml \
  --env-file .env \
  --profile supplier-gateway \
  up -d supplier-gateway-postgres
```

Jangan mulai `supplier-order-gateway` sebelum migrasi selesai: service tersebut langsung menjalankan consumer. Lihat bagian [Migrasi database](#migrasi-database) terlebih dahulu. RabbitMQ Management tersedia di `http://localhost:15672`; gunakan kredensial lokal dari `.env`.

## Migrasi database

Migrasi runtime bersifat manual dan harus dijalankan pada service yang benar. Periksa status terlebih dahulu; jangan menjalankan `migrate:fresh`.

Inventory:

```bash
./vendor/bin/sail artisan migrate:status
./vendor/bin/sail artisan migrate --force
```

Gateway:

```bash
docker compose \
  -f supplier-order-gateway/compose.yaml \
  --env-file .env \
  --profile supplier-gateway \
  run --rm --no-deps --entrypoint php supplier-order-gateway artisan migrate:status

docker compose \
  -f supplier-order-gateway/compose.yaml \
  --env-file .env \
  --profile supplier-gateway \
  run --rm --no-deps --entrypoint php supplier-order-gateway artisan migrate --force
```

Perintah Gateway hanya menargetkan `supplier_order_gateway` pada service `supplier-gateway-postgres`; Inventory menargetkan `laravel` pada service `pgsql`. Pastikan service Gateway PostgreSQL sudah berjalan, periksa status dan cadangan yang sesuai sebelum migrasi. Jangan gunakan `migrate:fresh`.

Setelah migrasi Gateway berhasil, mulai consumer:

```bash
docker compose \
  -f supplier-order-gateway/compose.yaml \
  --env-file .env \
  --profile supplier-gateway \
  up -d --build supplier-order-gateway
```

Periksa status dengan `docker compose -f supplier-order-gateway/compose.yaml --env-file .env --profile supplier-gateway ps`.

## Outbox Relay dan Consumer

Jalankan relay secara manual setelah migrasi Inventory selesai:

```bash
./vendor/bin/sail artisan outbox:relay --limit=100
```

Alternatifnya, jika Gateway sudah berjalan, jalankan consumer secara manual:

```bash
docker compose \
  -f supplier-order-gateway/compose.yaml \
  --env-file .env \
  --profile supplier-gateway \
  exec supplier-order-gateway php artisan stock-minimum:consume
```

Consumer berjalan sampai dihentikan dengan `Ctrl+C` atau sinyal `SIGTERM`. Untuk pemrosesan terbatas, tambahkan `--max-messages=10`. ACK dilakukan setelah notifikasi tersimpan; `eventId` unik mencegah pencatatan ganda. Gateway hanya mencatat notifikasi stok minimum dan tidak membuat atau mengirim pesanan supplier.

**Peringatan broker bersama:** service Gateway terhubung ke RabbitMQ runtime Inventory dan queue `supplier-order.stock-minimum-reached`. Menjalankan consumer akan mengambil pesan dari queue tersebut. Jangan mulai consumer atau relay pada broker runtime utama tanpa persetujuan operasional dan pemeriksaan antrean yang sesuai. Jangan mengaktifkan integration test RabbitMQ melalui profile ini terhadap broker utama.

**Retry dan dead-letter berbeda per aplikasi:** Inventory outbox melakukan retry publish (maksimum 5 percobaan termasuk percobaan pertama, exponential backoff dengan basis 5 detik dan batas maksimum 15 menit). Setelah batas itu, event berstatus `dead_lettered` di database Inventory; ini bukan RabbitMQ DLQ. Consumer Gateway belum memiliki kebijakan retry atau RabbitMQ DLQ khusus. Pesan yang gagal diproses tidak di-ACK dan dapat dikirim ulang broker setelah koneksi ditutup; penanganan operasionalnya masih perlu disepakati.

## Pengujian

Jalankan test Inventory melalui PostgreSQL database `testing` yang disiapkan Sail:

```bash
./vendor/bin/sail test
```

Jalankan test Gateway menggunakan PostgreSQL testing terpisah dengan filesystem `tmpfs`. Migrasikan database test terlebih dahulu:

```bash
docker compose \
  -f supplier-order-gateway/compose.yaml \
  --env-file .env \
  --profile supplier-gateway-testing \
  run --rm supplier-gateway-test-runner php artisan migrate --env=testing

docker compose \
  -f supplier-order-gateway/compose.yaml \
  --env-file .env \
  --profile supplier-gateway-testing \
  run --rm supplier-gateway-test-runner php artisan test
```

Database test memakai nama `testing`, user `gateway_test`, dan kredensial test lokal yang ditetapkan di Compose. Database tersebut terpisah dari database runtime Gateway; data pada `tmpfs` hilang saat container PostgreSQL test dihentikan. Test suite memakai konfigurasi `RUN_RABBITMQ_INTEGRATION_TESTS=0`; jangan mengaktifkan integration test dengan kredensial/network RabbitMQ runtime. Untuk integration test, gunakan broker test khusus yang terisolasi dan konfigurasi yang disetujui. Profile test memakai network `warung-pak-hendy_sail`, sehingga tidak boleh dianggap terisolasi dari broker runtime hanya karena database test-nya terpisah.

Test Inventory hanya mencakup suite Unit dan Feature di konfigurasi PHPUnit default; test publisher RabbitMQ Integration berada di luar suite tersebut dan memerlukan broker test terisolasi.

## Alur integrasi event

Secara fungsional, transaksi dapat membuat event stok minimum di outbox Inventory, lalu relay menerbitkannya dan Gateway mencatat notifikasi. Endpoint utama adalah:

- `GET /api/v1/products`: daftar produk aktif.
- `POST /api/v1/products`: membuat produk.
- `GET /api/v1/products/{productId}`: detail produk.
- `PUT /api/v1/products/{productId}`: mengganti field produk yang dapat diedit.
- `DELETE /api/v1/products/{productId}`: mengarsipkan produk.
- `POST /api/v1/transactions`: membuat transaksi; wajib menyertakan `Idempotency-Key`.

Kontrak lengkap request/response berada di [`openapi.yaml`](./openapi.yaml). Error API menggunakan Problem Details. Alur event adalah `StockMinimumReached` → exchange `inventory.events` → routing key `inventory.stock.minimum_reached` → queue `supplier-order.stock-minimum-reached` → pencatatan deduplicated pada database Gateway.

Contoh payload produk dan transaksi mengikuti kontrak di [`openapi.yaml`](./openapi.yaml).

### Integrasi lokal dengan broker runtime bersama

Compose Gateway memakai network `warung-pak-hendy_sail`, yaitu network yang sama dengan RabbitMQ Inventory. Relay dan consumer dapat memublikasikan/mengonsumsi pesan runtime. Jangan menjalankan urutan transaksi, relay, atau consumer sebagai demo pada broker runtime utama tanpa persetujuan, peninjauan antrean, dan data demo yang disiapkan.

### Demo broker terisolasi

Stage 8.1 berhasil menguji pencatatan dan deduplikasi dengan environment demo dan broker terisolasi, terpisah dari RabbitMQ runtime utama. Setup tersebut merupakan environment pengujian tahap tersebut, **bukan script atau prosedur demo turnkey yang disediakan repository**. Jangan menganggap perintah Compose lokal di atas sebagai cara menjalankan demo terisolasi.

## Anggota dan pembagian tugas

| Anggota | NIM | Pembagian tugas |
|---|---:|---|
| Difki Junaedi | 3224005 | Belum diverifikasi; lengkapi berdasarkan kontribusi dan riwayat commit. |
| Nurul Hidayah | 3224002 | Belum diverifikasi; lengkapi berdasarkan kontribusi dan riwayat commit. |
| Bima Azmi Khoerullah | 3224017 | Belum diverifikasi; lengkapi berdasarkan kontribusi dan riwayat commit. |

Untuk pemeriksaan lokal, pastikan relay terhubung ke RabbitMQ dan aplikasi Gateway terhubung ke database Gateway; jangan mengarahkan salah satu aplikasi ke database milik aplikasi lainnya.

## Troubleshooting

- **Gateway tidak dapat bergabung ke network:** mulai Compose Inventory dahulu dan periksa `docker network inspect warung-pak-hendy_sail`. Nama network sengaja stabil agar dua project Compose tetap dapat berkomunikasi.
- **RabbitMQ connection refused:** periksa status dan health service `rabbitmq`, serta gunakan `RABBITMQ_HOST=rabbitmq`, port `5672`, virtual host `/`, dan kredensial yang sama dari `.env` root.
- **Authentication RabbitMQ gagal:** RabbitMQ menyimpan akun pada volume persisten. Mengubah `.env` saja tidak merotasi akun yang sudah tersimpan.
- **Gateway PostgreSQL authentication gagal:** pastikan `SUPPLIER_GATEWAY_DB_PASSWORD` sesuai password user `gateway` pada volume Gateway yang sudah ada. Mengubah environment saja tidak mengubah password PostgreSQL yang sudah diinisialisasi.
- **Gateway tidak bisa mencapai Inventory PostgreSQL:** ini memang disengaja. Gateway hanya terhubung ke database Gateway; komunikasi antaraplikasi dilakukan melalui RabbitMQ.
- **Port host bentrok:** ubah port forward yang sesuai di `.env` sebelum `up`; jangan menghapus volume untuk mengatasi konflik port.
