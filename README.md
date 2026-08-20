# masJUBEL - Gold Price Aggregator API

[![Laravel Version](https://img.shields.io/badge/Laravel-12.x-red.svg)](https://laravel.com)
[![PHP Version](https://img.shields.io/badge/PHP-8.2%2B-blue.svg)](https://php.net)
[![License](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)

**masJUBEL** adalah API Agregator Harga Emas berbasis Laravel yang dirancang untuk melakukan *scraping* data secara otomatis dari berbagai situs penyedia/produsen emas terkemuka di Indonesia. Data yang berhasil dikumpulkan disimpan secara berkala menggunakan Scheduler, kemudian diekspos melalui endpoint RESTful API untuk kebutuhan integrasi frontend atau aplikasi eksternal.

---

## 🚀 Fitur Utama

- **Multi-Source Web Scraping**: Mendukung ekstraksi data secara berkala dari 4 situs utama:
  1. [Logam Mulia (Antam)](https://www.logammulia.com/id/harga-emas-hari-ini)
  2. [UBS Lifestyle](https://ubslifestyle.com/harga-buyback-hari-ini/)
  3. [Hartadinata Gold](https://hrtagold.id/id/gold-price)
  4. [Sampoerna Gold](https://sampoernagold.com/)
- **Trend Calculation & Historical Data**: Menyajikan perhitungan tren harga (kenaikan/penurunan) serta format data historis yang siap digunakan untuk pustaka *chart* di frontend.
- **RESTful API (JSON)**: API terstruktur dengan penanganan versi (v1) dan format respon yang konsisten.

---

## 🛠️ Teknologi & Library

- **Framework**: Laravel 12+
- **HTTP Client**: GuzzleHttp
- **HTML Parser**: Symfony DomCrawler
- **Database**: Eloquent ORM (MySQL / PostgreSQL / SQLite)
- **Engine**: PHP 8.2+

---

## 📂 Struktur Proyek Utama

Berikut adalah letak berkas-berkas penting dalam proyek ini:

```bash
├── app/
│   ├── Console/
│   │   └── Commands/              # Logika dan Perintah Scraper (Artisan CLI)
│   │       ├── ScrapeHartadinataGold.php
│   │       ├── ScrapeLogamMuliaGold.php
│   │       ├── ScrapeSampoernaGold.php
│   │       └── ScrapeUbsGold.php
│   ├── Http/
│   │   └── Controllers/
│   │       └── Api/
│   │           └── V1/
│   │               └── GoldPriceController.php   # Endpoint API Controller
│   └── Models/
│       ├── Source.php             # Model Master Situs Emas
│       └── GoldPrice.php          # Model Riwayat/Detail Harga Emas
├── routes/
│   └── api.php                    # Routing RESTful API
```

---

## ⚙️ Cara Instalasi & Konfigurasi

### 1. Klon Repositori
```bash
git clone https://github.com/username/masjubel-backend.git
cd masjubel-backend
```

### 2. Instalasi Dependensi
```bash
composer install
```

### 3. Konfigurasi Environment
Salin berkas `.env.example` ke `.env` dan konfigurasikan koneksi database Anda:
```bash
cp .env.example .env
php artisan key:generate
```

### 4. Migrasi & Seed Database
Jalankan migrasi database beserta seeder untuk mengisi data master `sources` (situs emas):
```bash
php artisan migrate --seed
```

### 5. Menjalankan Scraper Secara Manual
# Jalankan scraper secara spesifik sesuai command signature:
php artisan scrape:hartadinata-gold
php artisan scrape:sampoerna-gold
php artisan scrape:logam-mulia-gold
php artisan scrape:ubs-gold

---

## ⏱️ Otomatisasi (Task Scheduling)

Agar scraper berjalan otomatis di server, tambahkan entri Cron berikut ke server Anda:

```bash
* * * * * cd /path-to-your-project && php artisan schedule:run >> /dev/null 2>&1
```

Aplikasi telah dikonfigurasi untuk menjalankan semua scraper pada interval waktu yang ditentukan secara asinkron guna menghindari *blocking process*.

---

## 🔌 API Dokumentasi (REST API)

Semua endpoint API menggunakan prefix `/api/v1`.

### 1. Ambil Harga Terbaru Semua Sumber
* **Endpoint**: `GET /api/v1/gold-prices/all`
* **Deskripsi**: Mengembalikan daftar harga emas pecahan lengkap yang paling terbaru dari seluruh situs sumber.
* **Respon Sukses (200 OK)**:
```json
{
  "status": "success",
  "message": "Success fetch all latest gold prices data",
  "data": [
    {
      "source_name": "Hartadinata Gold",
      "source_slug": "hartadinata",
      "source_url": "https://hrtagold.id/id/gold-price",
      "last_updated": "2026-06-06T16:00:00Z",
      "prices": [
        {
          "weight": 1.0,
          "base_price": 1350000,
          "buyback_price": 1240000
        }
      ]
    }
  ]
}
```

### 2. Rekap Sorotan Harga 1 Gram (Highlight)
* **Endpoint**: `GET /api/v1/gold-prices/highlight`
* **Deskripsi**: Mengambil harga emas 1 gram terbaru dari tiap situs beserta persentase kenaikan/penurunan harga dibanding hari sebelumnya.
* **Respon Sukses (200 OK)**:
```json
{
  "status": "success",
  "message": "Success fetch highlight 1 gram gold price data",
  "data": [
    {
      "source_name": "Hartadinata Gold",
      "weight": 1,
      "current_price": 1350000,
      "previous_price": 1340000,
      "trend_percentage": 0.75,
      "is_up": true,
      "last_updated": "2026-06-06T16:00:00Z"
    }
  ]
}
```

### 3. Riwayat Grafik Harga (History)
* **Endpoint**: `GET /api/v1/gold-prices/history?source={source_slug}&range={days}`
* **Parameter**:
  - `source` (opsional): Slug dari source (contoh: `hartadinata`, `antam`, `ubs`). Default: `antam`.
  - `range` (opsional): Jumlah hari ke belakang. Default: `7`.
* **Respon Sukses (200 OK)**:
```json
{
  "status": "success",
  "message": "Succeed fetch history Hartadinata Gold for 7 last days",
  "data": {
    "source_name": "Hartadinata Gold",
    "range_days": 7,
    "chart_data": [
      {
        "date": "2026-05-30",
        "price": 1340000
      },
      {
        "date": "2026-06-06",
        "price": 1350000
      }
    ]
  }
}
```

---

## 📄 Lisensi

Proyek ini dilisensikan di bawah lisensi MIT. Silakan gunakan dan modifikasi secara bebas.
