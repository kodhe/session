# Kodhe Session

Package session modular hasil refaktor library Session CodeIgniter 3, dengan namespace PSR-4 (`Kodhe\Framework\Session\`) dan `declare(strict_types=1)`. Tetap 100% kompatibel mundur terhadap API CI3 (`userdata()`, `set_userdata()`, `flashdata()`, dst.) tetapi internally memakai design pattern modern: driver abstraction, value object `SessionId`, serta manager terpisah untuk flash data, cookie, dan storage.

Driver yang tersedia: **Files** (default), **Database**, **Redis**, **Memcached**.

## Instalasi

```bash
composer require kodhe/session
```

Persyaratan: PHP >= 8.1. Saran: `ext-redis` / `ext-memcached` bila memakai driver terkait.

## Quick Start

```php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Kodhe\Framework\Session\Session;

$session = new Session([
    'sess_driver'  => 'files',
    'sess_expiration' => 7200,
]);

// Menyimpan data
$session->set_userdata('user_id', 42);
$session->set_userdata(['name' => 'Budi', 'role' => 'admin']);

// Membaca data
echo $session->userdata('user_id');   // 42
var_dump($session->has_userdata('name')); // true

// Flash data (hilang setelah satu kali baca)
$session->set_flashdata('notice', 'Berhasil disimpan!');
echo $session->flashdata('notice');

// Menghapus
$session->unset_userdata('role');
$session->sess_destroy();
```

## Struktur Direktori

```
src/
├── Session.php                 # Class utama (implements SessionInterface)
├── Driver.php                  # Base class driver
├── HandlerInterface.php
├── Contracts/                  # SessionInterface, StorageInterface, dll.
├── Drivers/                    # FilesDriver, DatabaseDriver, RedisDriver, MemcachedDriver
├── Factory/DriverFactory.php   # Pembuatan driver berdasar konfigurasi
├── Flash/FlashDataManager.php  # Logika flashdata & tempdata
├── Storage/                    # SessionStorage, NullStorage
├── Support/                    # SessionConfig, CookieManager, SessionIdGenerator
├── ValueObjects/SessionId.php
└── helpers.php                 # Helper global (autoload via composer "files")
```

## Penggunaan

### 1. Flashdata & Tempdata

```php
// Flashdata: bertahan sampai satu kali baca berikutnya
$session->set_flashdata('error', 'Email sudah terdaftar');
$session->keep_flashdata('error');   // perpanjang satu request lagi

// Tempdata: bertahan berdasarkan TTL (detik), default 300
$session->set_tempdata('draft', ['title' => 'Draf artikel'], ttl: 600);
$draft = $session->tempdata('draft');
```

### 2. Regenerasi ID (anti session fixation)

```php
$session->sess_regenerate(destroy: false); // ID baru, data lama tetap
```

### 3. Memilih driver lain

```php
$session = new Session([
    'sess_driver'     => 'database',
    'sess_save_path'  => 'ci_sessions',       // nama tabel
    'sess_expiration' => 7200,
]);

// Redis
$session = new Session([
    'sess_driver'    => 'redis',
    'sess_save_path' => 'tcp://127.0.0.1:6379?weight=1',
]);
```

Tabel penyimpanan driver `database` (MySQL):

```sql
CREATE TABLE `ci_sessions` (
    `id`         VARCHAR(128) NOT NULL,
    `ip_address` VARCHAR(45)  NOT NULL,
    `timestamp`  INT UNSIGNED NOT NULL,
    `data`       BLOB NOT NULL,
    PRIMARY KEY (`id`)
);
```

> **Wajib!** Jika tabel belum dibuat — atau nama kolom berbeda dari skema di atas —
> driver akan melempar `Exception` dengan pesan jelas saat request pertama
> ("Session table ... is missing the required column 'data'"), bukan error
> `Unknown column 'data' in 'SELECT'` yang membingungkan. Untuk migrasi dari
> skema CI3 lama yang memakai kolom `lastactivity`, kolom expiry otomatis
> dideteksi saat garbage collection; namun kolom `id`, `ip_address`, dan
> `data` tetap wajib ada. Alternatif cepat untuk development: gunakan
> `'sess_driver' => 'files'`.

## Konfigurasi (key umum)

| Opsi | Tipe | Default | Keterangan |
|------|------|---------|------------|
| `sess_driver` | string | `files` | `files`, `database`, `redis`, `memcached` |
| `sess_expiration` | int | `7200` | Umur session dalam detik |
| `sess_save_path` | string | `''` | Path folder / tabel / koneksi driver |
| `cookie_name` | string | `ci_session` | Nama cookie session |
| `cookie_secure` | bool | `false` | Kirim cookie hanya via HTTPS |
| `cookie_httponly` | bool | `true` | Cookie tidak bisa dibaca JavaScript |

## API Referensi (Class Utama)

| Method | Deskripsi |
|--------|-----------|
| `userdata($key = null)` | Baca satu/seluruh data session |
| `set_userdata($data, $value = null)` | Simpan data (array atau key–value) |
| `unset_userdata($key)` | Hapus data |
| `has_userdata(string $key)` | Cek keberadaan key |
| `all_userdata()` | Semua data session sebagai array |
| `set_flashdata()` / `flashdata()` / `keep_flashdata()` | Flash data sekali baca |
| `set_tempdata()` / `tempdata()` | Data sementara berbasis TTL |
| `sess_destroy()` | Hancurkan session |
| `sess_regenerate(bool $destroy = false)` | Regenerasi ID session |

## Kompatibilitas CodeIgniter 3

- Seluruh nama method CI3 dipertahankan, termasuk properti publik `$userdata` (referensi ke `$_SESSION`).
- Helper di `src/helpers.php` dimuat otomatis lewat composer `files` autoload sehingga gaya pemanggilan prosedural lama tetap tersedia.

## Catatan & Batasan

- Driver `redis`/`memcached` membutuhkan ekstensi PHP terkait; `DriverFactory` melempar `SessionException` bila driver tak tersedia.
- Package ini berdiri sendiri (`kodhe/session`) namun helper routing URL-nya disediakan oleh package `kodhe/http`.

## Pengujian

```bash
cd session && composer install && vendor/bin/phpunit
```

## Lisensi

MIT. Lihat [LICENSE](../LICENSE).
