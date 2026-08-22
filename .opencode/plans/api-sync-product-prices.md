# API Sync Harga Products (`POST /v1/sync/product-prices`)

## Konteks
Sudah ada command `sync:product-prices` (`SyncProductPrices.php`) yang mencocokkan `gold_prices` ↔ `products` (DB sekunder `masjubel`) berdasarkan `source_id` + `weight`, lalu update `products.price`.

## Keputusan (dari user)
1. **Logika baru**: jika source produk bertipe **komoditas** (`sources.is_commodity = true`), harga diambil dari baris `weight=1` (harga per gram) lalu **dikalikan weight produk**. Source emas biasa tetap match persis per weight seperti sekarang.
2. Endpoint **publik** (tanpa X-API-KEY).
3. Pendekatan: refactor ke **service bersama** agar command & API memakai satu logika dan respons API terstruktur `{updated, skipped}`.

## Perubahan File

### 1. Service baru — `app/Services/ProductPriceSyncService.php`
Satu method publik `sync(): array` mengembalikan `['updated' => int, 'skipped' => int]`.

Logika (dipindah + dimodifikasi dari command):
```
products   = DB sekunder (mysql_secondary): select id, source_id, weight
sourceFlags= map [source_id => is_commodity] dari tabel sources (koneksi utama)

untuk tiap product:
  query = gold_prices where source_id = product.source_id
  jika komoditas → where('weight', 1)
  jika bukan     → where('weight', product.weight)
  base_price = query orderByDesc('recorded_at')->value('base_price')

  jika null → skipped++, lanjut
  harga akhir = komoditas ? round(base_price * product.weight) : base_price
  update products set price = harga akhir (+updated_at)
  updated++
```
Catatan implementasi:
- Pakai Eloquent `Source` / `GoldPrice` (koneksi utama) agar konsisten dengan sisa kodebase; products tetap query builder koneksi `mysql_secondary`.
- Guard: `weight` produk null/≤0 → di-skip (mencegah perkalian liar di branch komoditas).
- Perkalian komoditas dibulatkan `(int) round(...)`.

### 2. `app/Console/Commands/SyncProductPrices.php`
- `handle()` tinggal panggil service, tampilkan hasil via `$this->info("Selesai. Updated: X, Skipped: Y")`.
- Deskripsi command diperbarui (menyebut rule komoditas ×weight).

### 3. Controller baru — `app/Http/Controllers/Api/V1/SyncController.php`
Method `productPrices()`:
```json
{
  "status": "success",
  "message": "Success synced product prices",
  "data": { "updated": 12, "skipped": 3 }
}
```
Tanpa middleware (publik).

### 4. Route — `routes/api.php` (grup v1)
```php
Route::post('/sync/product-prices', [SyncController::class, 'productPrices']);
```

### 5. Postman Collection
Request baru "Sync Product Prices" (POST `{{base_url}}/v1/sync/product-prices`, auth noauth) di folder "Scrape" atau folder baru "Sync".

## Yang Tidak Berubah
- `ScrapeController::run` tetap `Artisan::call('sync:product-prices')` — otomatis ikut dapat logika baru lewat command → service.
- Struktur tabel & endpoint lain tidak disentuh.

## Verifikasi
1. `php -l` semua file; `php artisan route:list --path=v1/sync`.
2. Regresi command: `php artisan sync:product-prices` (bandingkan angka updated/skipped sebelum–sesudah untuk produk non-komoditas).
3. Curl `POST http://127.0.0.1:8010/api/v1/sync/product-prices` (tanpa header) → 200 JSON updated/skipped.
4. Jika DB sekunder punya produk source komoditas (emas/perak/tembaga): verifikasi `price = base_price(1g) × weight` di tabel products.
5. Produk dengan weight tidak cocok / source tanpa data → masuk skipped, tidak error.
