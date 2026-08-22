# Price-Table: Gabungkan Semua Source `is_commodity` jadi 1 Respons (`source=lainnya`)

## Keputusan (dari user)
1. Trigger respons gabungan: request dengan **`?source=lainnya`** (slug virtual, bukan row di tabel sources).
2. **Tabs digabung**: tab Emas/Perak/Tembaga terpisah diganti 1 tab gabungan `{name: "Komoditas", slug: "lainnya", type: "commodity"}`.
3. Slug individual komoditas (`emas`, `perak`, `tembaga`) **tetap berfungsi** seperti sekarang jika di-request langsung.

## Perubahan

### 1. `app/Http/Controllers/Api/V1/GoldPriceController.php` — `priceTable()`

Konstanta: `$commoditySlug = 'lainnya';` dan `$commodityName = 'Komoditas';`

**Tabs** — pisahkan hasil map:
- Source bertipe `gold` → tab individual seperti sekarang.
- Semua source bertipe `commodity` → diganti **satu** tab `{name: $commodityName, slug: $commoditySlug, type: 'commodity'}` (hanya jika ada), ditempatkan di urutan terakhir tabs.

**Branch gabungan** — sebelum lookup `$activeSource`, cek:
```php
if ($sourceSlug === $commoditySlug && ada source komoditas) {
    // Query tanpa filter source_id:
    // GoldPrice where is_commodity=true & weight=1
    // joinSub MAX(DATE(recorded_at)) per source_id (latest_dates tetap group by source_id)
    // orderBy source_id agar urutan rows stabil (Emas, Perak, Tembaga sesuai id)
    // dedupe ->unique(fn ($p) => $p->source_id.'-'.$p->weight)  ← PENTING:
    //   unique('weight') lama akan salah menelen semua source karena semuanya weight=1
    //
    // active_source = ['name' => 'Komoditas', 'slug' => 'lainnya', 'type' => 'commodity']
    // last_updated  = max(recorded_at) antar semua rows
    // rows          = map bentuk lama, field 'commodity' dari nama source masing-masing
    //                 ($price->source->name, eager-load tidak wajib, N kecil)
    // return response sama seperti sekarang;
}
```
- Branch existing (gold / commodity single-source via `resolveSourceType`) **tidak diubah**.

### 2. Postman collection — deskripsi endpoint "Get Price Table"
Tambah catatan: `?source=lainnya` mengembalikan gabungan semua komoditas; slug individual masih didukung.

## Contoh Respons Gabungan
```
GET /api/v1/gold-prices/price-table?source=lainnya
{
  "data": {
    "tabs": [ ...tab gold..., {"name":"Komoditas","slug":"lainnya","type":"commodity"} ],
    "active_source": {"name":"Komoditas","slug":"lainnya","type":"commodity"},
    "last_updated": "<max recorded_at>",
    "rows": [
      {"commodity":"Emas","purity":null,"buy_price_per_gram":1585000,"buyback_price":1525000,"unit":"per gram"},
      {"commodity":"Perak", ...},
      {"commodity":"Tembaga", ...}
    ]
  }
}
```

## Verifikasi
1. `?source=lainnya` → 3 rows gabungan + tab gabungan ada, tab emas/perak/tembaga hilang.
2. `?source=perak` langsung → tetap 1 row Perak (regresi).
3. `?source=antam` → gold branch tidak berubah.
4. Dedupe: POST harga emas dobel di hari sama → rows tetap 3.
