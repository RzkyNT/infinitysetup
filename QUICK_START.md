# Quick Start - JSON Database Mode

## 🎯 Cara Menggunakan Database JSON di Adminer

### Langkah 1: Aktifkan Mode JSON
1. Buka `adminer.php` di browser
2. Login seperti biasa
3. Lihat sidebar di sebelah kiri
4. Klik tombol **"JSON"** (di samping tombol "SQL")

### Langkah 2: Pilih File JSON
1. Setelah mode JSON aktif, akan muncul dropdown "Pilih JSON File"
2. Pilih **"example.json"** untuk mencoba
3. Atau buat file JSON sendiri (lihat langkah 4)

### Langkah 3: Gunakan Seperti Database SQL
Setelah memilih file JSON:
- **Tabel** akan muncul di sidebar (contoh: users, products)
- **Klik tabel** untuk melihat data
- **Semua fitur** bekerja sama seperti mode SQL:
  - ✅ View data (lihat data)
  - ✅ Add row (tambah baris)
  - ✅ Edit row (edit baris)
  - ✅ Delete row (hapus baris)
  - ✅ Inline editing (double-click cell untuk edit)
  - ✅ Search & filter
  - ✅ Sort (urutkan)
  - ✅ Pagination

### Langkah 4: Buat File JSON Sendiri

#### Cara 1: Menggunakan Tombol "New File" (RECOMMENDED)
1. Pastikan Anda dalam mode JSON
2. Klik tombol **"New File"** (tombol hijau dengan icon +)
3. Masukkan nama file (contoh: `mydata.json`)
4. Klik "Create"
5. File akan otomatis dibuat dan dipilih
6. Mulai tambahkan tabel dan data

#### Cara 2: Manual (Buat File Sendiri)

#### Format File JSON:
```json
{
    "nama_tabel": [
        {
            "id": 1,
            "field1": "value1",
            "field2": "value2"
        },
        {
            "id": 2,
            "field1": "value3",
            "field2": "value4"
        }
    ],
    "tabel_lain": [
        {
            "id": 1,
            "nama": "Contoh"
        }
    ]
}
```

#### Contoh File JSON (users.json):
```json
{
    "users": [
        {
            "id": 1,
            "name": "John Doe",
            "email": "john@example.com",
            "role": "admin"
        },
        {
            "id": 2,
            "name": "Jane Smith",
            "email": "jane@example.com",
            "role": "user"
        }
    ],
    "posts": [
        {
            "id": 1,
            "user_id": 1,
            "title": "Hello World",
            "content": "This is my first post"
        }
    ]
}
```

#### Cara Membuat:
1. Buat file baru di folder `json_db/`
2. Beri nama, contoh: `mydata.json`
3. Copy format di atas dan sesuaikan dengan data Anda
4. Save file
5. Refresh adminer.php
6. Pilih file JSON Anda dari dropdown

---

## 🔄 Perbedaan SQL vs JSON Mode

| Fitur | SQL Mode | JSON Mode |
|-------|----------|-----------|
| Database Server | Perlu MySQL | Tidak perlu |
| Setup | Perlu konfigurasi | Cukup buat file JSON |
| CRUD Operations | ✅ | ✅ |
| Inline Editing | ✅ | ✅ |
| Search & Filter | ✅ | ✅ (per kolom) |
| Sort & Pagination | ✅ | ✅ |
| Complex Queries | ✅ | ❌ |
| Best For | Large datasets | Small-medium datasets |
| File Size Limit | Unlimited | < 10MB recommended |

---

## 💡 Tips & Trik

### 1. Auto-Increment ID
- ID akan otomatis bertambah saat insert data baru
- Tidak perlu manual set ID

### 2. Inline Editing
- **Double-click** cell untuk edit langsung
- Tekan **Enter** atau klik di luar untuk save
- Perubahan langsung tersimpan ke file JSON

### 3. Backup Data
- File JSON ada di folder `json_db/`
- Bisa di-copy untuk backup
- Bisa di-edit manual dengan text editor

### 4. Import/Export
- Export: Gunakan fitur export seperti biasa
- Import: Edit file JSON langsung atau gunakan fitur import

### 5. Multiple Files
- Bisa punya banyak file JSON
- Setiap file = 1 database
- Switch antar file dengan dropdown

### 6. Mengelola File JSON
- **Create:** Klik tombol "New File" untuk membuat file baru
- **Delete:** Klik tombol "Delete" (merah) untuk hapus file yang sedang aktif
- **Backup:** Copy file dari folder `json_db/` untuk backup

---

## 🎨 Fitur Tambahan yang Sudah Terintegrasi

### 1. Media Display
- Kolom dengan nama: image, photo, avatar, video, dll
- Otomatis tampilkan thumbnail
- Klik untuk lihat full size

### 2. Searchable Dropdowns
- Semua dropdown bisa di-search
- Ketik untuk filter pilihan
- Lebih mudah untuk data banyak

### 3. Dark Theme
- Klik icon theme di top bar
- Toggle antara dark/light mode
- Preferensi tersimpan di browser

---

## 🗂️ Mengelola File JSON

### Membuat File JSON Baru
1. **Klik Mode JSON** di sidebar
2. **Klik tombol "New File"** (tombol hijau dengan icon +)
3. **Masukkan nama file:**
   - Contoh: `mydata.json`, `users.json`, `products.json`
   - Harus diakhiri dengan `.json`
   - Hanya boleh huruf, angka, underscore (_), dan hyphen (-)
4. **Klik "Create"**
5. File akan otomatis dibuat dengan tabel default
6. Anda bisa langsung mulai menambahkan data

### Menghapus File JSON
1. **Pilih file** yang ingin dihapus dari dropdown
2. **Klik tombol "Delete"** (tombol merah dengan icon trash)
3. **Konfirmasi** penghapusan
4. File akan dihapus permanen (tidak bisa di-undo!)

### Tips Keamanan
- ⚠️ **Backup dulu** sebelum menghapus file
- 💾 File JSON ada di folder `json_db/`
- 📋 Copy file untuk backup sebelum edit besar-besaran
- 🔒 Pastikan folder `json_db/` tidak bisa diakses langsung dari browser

---

## 📂 Struktur Folder

```
your-project/
├── adminer.php           # File utama
├── JsonDatabase.php      # Class JSON database
├── json_db/              # Folder untuk file JSON
│   ├── example.json      # Contoh file (sudah ada)
│   ├── mydata.json       # File Anda (buat sendiri)
│   └── ...
├── query-builder.js      # Query builder (opsional)
├── advanced-filters.js   # Advanced filters (opsional)
└── query-builder.css     # Styling
```

---

## ❓ FAQ

**Q: Apakah data JSON aman?**
A: File JSON disimpan di server, sama seperti database SQL. Pastikan folder `json_db/` tidak bisa diakses langsung dari browser.

**Q: Berapa batas ukuran file JSON?**
A: Rekomendasi < 10MB untuk performa optimal. Untuk data lebih besar, gunakan SQL mode.

**Q: Bisa pakai JSON dan SQL bersamaan?**
A: Ya! Tinggal switch mode dengan tombol SQL/JSON di sidebar.

**Q: Apakah perlu install sesuatu?**
A: Tidak! Semua sudah terintegrasi. Cukup buat file JSON dan gunakan.

**Q: Bagaimana cara backup data JSON?**
A: Copy file JSON dari folder `json_db/` ke tempat aman.

**Q: Bisa edit file JSON manual?**
A: Ya, bisa edit dengan text editor. Pastikan format JSON valid.

---

## 🚀 Contoh Use Case

### Use Case 1: Website Kecil
- Tidak perlu database server
- Data user, posts, settings di JSON
- Mudah deploy (cukup upload file)

### Use Case 2: Prototyping
- Cepat buat mockup data
- Tidak perlu setup database
- Mudah ubah struktur data

### Use Case 3: Configuration Management
- Simpan config aplikasi
- Mudah edit dan backup
- Version control friendly

### Use Case 4: Development/Testing
- Test fitur tanpa database
- Mudah reset data (hapus file JSON)
- Cepat switch antar dataset

---

## 📞 Support

Jika ada masalah:
1. Cek file `INTEGRATION_STATUS.md` untuk status fitur
2. Cek file `IMPLEMENTATION_GUIDE.md` untuk detail teknis
3. Pastikan file JSON format valid (gunakan JSON validator online)
4. Cek browser console untuk error JavaScript

---

**Selamat menggunakan JSON Database Mode!** 🎉

Semua fitur sudah terintegrasi dan siap digunakan. Tidak perlu setup tambahan, langsung bisa pakai!
