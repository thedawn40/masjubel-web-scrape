# Refactor: Hapus Tabel `commodity_prices` → Flag `is_commodity` di `gold_prices`

## Keputusan (dari user)
1. Tiap komoditas jadi **source sendiri** (slug: `emas`, `perak`, `tembaga`), harga disimpan di **gold_prices** dengan **weight=1**.
2. Data lama & kolom `purity`/`unit` → dibuang.
3. Kontrak API `/v1/gold-prices/price-table` **dipertahankan** (field `type`, bentuk rows komoditas) — tipe dideteksi via kolom baru **`is_commodity` (boolean)** di tabel `gold_prices`.
4. Route `POST /v1/commodity-prices` + `CommodityPriceController` **dihapus**.

## Perubahan File

### 1. Migration (baru, 2 file)
- `2026_08_22_220000_add_is_commodity_to_gold_prices_table.php`
  - `Schema::table('gold_prices')`: tambah `$table->boolean('is_commodity')->default(false)->after('buyback_price')`.
- `2026_08_22_230000_drop_commodity_prices_table.php`
  - `Schema::dropIfExists('commodity_prices')`.
- Migration lama (`create_commodity_prices_table`, `add_buyback_price_to_commodity_prices`) dibiarkan sebagai history.

### 2. Model
- **Hapus** `app/Models/CommodityPrice.php`.
- `app/Models/Source.php`: hapus relasi `commodityPrices()`; hapus `HasOne` import jika tak terpakai.

### 3. Controller
- **Hapus** `app/Http/Controllers/Api/V1/CommodityPriceController.php`.

#### `GoldPriceController`
- Buang import/model `CommodityPrice`.
- `priceTable()`:
  - Tabs query: buang `orWhereHas('commodityPrices')` → cukup `whereHas('goldPrices')`.
  - `resolveSourceType()`: ganti logika jadi
    `return $source->goldPrices()->where('is_commodity', true)->exists() ? 'commodity' : 'gold';`
  - Branch commodity (kontrak respons tetap): ambil GoldPrice `where('is_commodity', true)` tanggal terbaru untuk source aktif (pola sama dengan branch gold), map rows ke bentuk lama:
    ```php
    [
      'commodity'          => $activeSource->name,
      'purity'             => null,
      'buy_price_per_gram' => (int) $price->base_price,
      'buyback_price'      => $price->buyback_price,
      'unit'               => 'per gram',
    ]
    ```
- `store()`:
  - Validasi tambah `'is_commodity' => ['nullable', 'boolean']` (level batch).
  - `GoldPrice::create([... 'is_commodity' => $validated['is_commodity'] ?? false])`.

#### `SourceController`
- `index()` / `show()`: `withCount`/`loadCount` hanya `goldPrices`.
- `destroy()`: guard hanya cek `goldPrices()->exists()`.

### 4. Routes
- `routes/api.php`: hapus route `POST /v1/commodity-prices` + import `CommodityPriceController`.

### 5. Seeder
- **Hapus** `database/seeders/CommodityPriceSeeder.php`.
- `SourceSeeder`: ganti entry `Lainnya` dengan 3 source komoditas:
  - Emas (`emas`), Perak (`perak`), Tembaga (`tembaga`) — url boleh string kosong.
- Seeder baru `CommodityPriceSeeder` (nama dipertahankan agar DatabaseSeeder tidak banyak berubah... ATAU rename): seed `GoldPrice` weight=1 per source komoditas dengan `is_commodity = true` (data harga contoh sama seperti sebelumnya: Emas 1.580.000/buyback 1.520.000, dst).
  - `DatabaseSeeder`: sesuaikan referensi.

### 6. Postman Collection
- Hapus folder "Commodity Prices".
- Update body contoh "Store Gold Prices": tambah `"is_commodity": false` + catatan validasi.
- Update deskripsi endpoint price-table (tipe kini dari flag).

## Yang Tidak Berubah
- Semua scraper command (tidak ada yang scrape komoditas).
- Endpoint `all`, `highlight`, `chart-data`, `buyback-simulation` — otomatis mengikutkan source komoditas karena datanya kini di `gold_prices` weight=1.
- `SyncProductPrices` (hanya baca gold_prices).

## Verifikasi
1. `php artisan migrate` → kolom & drop sukses.
2. `php artisan db:seed --class=SourceSeeder --class=CommodityPriceSeeder`.
3. Serve lokal + curl:
   - `GET /api/v1/gold-prices/price-table?source=perak` → `type: "commodity"`, rows bentuk lama.
   - `GET /api/v1/gold-prices/price-table?source=antam` → `type: "gold"` (regresi).
   - `POST /api/v1/gold-prices` dengan `is_commodity: true` → tersimpan flagged.
   - `GET /api/v1/gold-prices/all` & `highlight` → source komoditas ikut muncul.
4. Grep sisa referensi `commodity` memastikan bersih.
