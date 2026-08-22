# Pindahkan Flag `is_commodity` ke Tabel `sources`

## Keputusan (dari user)
- Tambah kolom **`is_commodity` boolean** di tabel `sources`.
- **Drop** kolom `is_commodity` dari `gold_prices` — tidak ada dua flag yang bisa tidak sinkron.
- Param `is_commodity` di `POST /v1/gold-prices` ikut dihapus (tipe sudah ditentukan source).

## Perubahan File

### 1. Migration (2 file baru)
- `2026_08_22_240000_add_is_commodity_to_sources_table.php`
  - `$table->boolean('is_commodity')->default(false)` (posisi setelah `url`, cek urutan kolom saat eksekusi).
- `2026_08_22_250000_drop_is_commodity_from_gold_prices_table.php`
  - `dropColumn(['is_commodity'])`; down() menambahkannya kembali.

### 2. Model `app/Models/Source.php`
- Tambah casts: `protected $casts = ['is_commodity' => 'boolean'];` (saat ini belum ada property casts).

### 3. `app/Http/Controllers/Api/V1/GoldPriceController.php`
- `resolveSourceType()`: ganti query exists dengan atribut:
  ```php
  return $source->is_commodity ? 'commodity' : 'gold';
  ```
- Branch komoditas single-source (`priceTable`): buang `->where('is_commodity', true)` (sudah dijamin oleh `$activeSource`).
- Branch gabungan: ganti filter `where('is_commodity', true)` dengan `whereIn('gold_prices.source_id', ...)`:
  - `priceTable` kirim `$commoditySources->pluck('id')->all()` ke method.
  - Signature jadi `combinedCommodityTableResponse(string $name, string $slug, Collection $tabs, array $sourceIds)`.
- `store()`: hapus validasi `'is_commodity'` dan penulisan kolom pada `GoldPrice::create()`.
- Bersihkan komentar/docblock yang menyebut flag di gold_prices.

### 4. Seeder
- `SourceSeeder`: entry `emas`/`perak`/`tembaga` tambah `'is_commodity' => true`.
- `CommodityPriceSeeder`: hapus `'is_commodity' => true` dari kondisi `updateOrCreate` (unik jadi `source_id` + `weight` saja).

### 5. `app/Http/Controllers/Api/V1/SourceController.php`
- `store()`: validasi `'is_commodity' => ['nullable', 'boolean']`, simpan `$validated['is_commodity'] ?? false`.
- `update()`: validasi sama, pakai `$validated['is_commodity'] ?? $source->is_commodity`.

### 6. Postman Collection
- Create/Update Source: contoh body + deskripsi dapat `"is_commodity"`.
- Store Gold Prices: hapus `"is_commodity"` dari contoh body & catatan validasi.
- Deskripsi price-table: tipe kini dari `sources.is_commodity` (bukan flag per baris).

## Catatan Perilaku
- Tipe source kini statik per source — source komoditas bertipe `commodity` bahkan sebelum punya baris harga (sebelumnya terdeteksi 'gold' saat kosong). Tabs tetap hanya memuat source yang punya harga (`whereHas('goldPrices')`), jadi tampilan tidak berubah.
- Data dev lama: kolom gold_prices.is_commodity hilang tanpa migrasi data — tidak masalah karena sumber kebenaran pindah ke sources.

## Verifikasi
1. `php artisan migrate --force` → 2 migrasi sukses.
2. Re-seed `SourceSeeder` + `CommodityPriceSeeder` → flag tersimpan di sources.
3. Curl:
   - `?source=lainnya` → 3 rows gabungan + spread_percentage tetap ada.
   - `?source=perak` / `?source=antam` → regresi normal.
   - `POST /v1/gold-prices` tanpa `is_commodity` → 201.
   - `POST /v1/sources` dengan `is_commodity: true` → tersimpan; `GET /v1/sources` memuat field baru.
   - `PUT /v1/sources/{id}` ubah flag → berlaku.
4. Grep sisa `is_commodity` → hanya referensi ke tabel/endpoint sources & seeder.
