# JSON File Management - Panduan Lengkap

## 🎯 Fitur Baru: Kelola File JSON dari Adminer

Sekarang Anda bisa membuat, browse, dan menghapus file JSON langsung dari interface Adminer tanpa perlu akses ke file system!

---

## ✨ Fitur yang Tersedia

### 1. Create New JSON File (Buat File Baru)
- ✅ Buat file JSON baru langsung dari UI
- ✅ Validasi nama file otomatis
- ✅ File langsung siap digunakan
- ✅ Tidak perlu akses FTP/SSH

### 2. Browse JSON File (Pilih File dari Server) **NEW!**
- ✅ Browse file JSON dari mana saja di server
- ✅ Navigasi folder seperti file manager
- ✅ Pilih file JSON yang sudah ada
- ✅ Tidak terbatas pada folder json_db/

### 3. Delete JSON File (Hapus File)
- ✅ Hapus file JSON yang tidak diperlukan
- ✅ Konfirmasi sebelum hapus (safety)
- ✅ Otomatis clear session jika file aktif dihapus

---

## 📍 Lokasi Tombol

Setelah switch ke mode JSON, Anda akan melihat:

```
┌─────────────────────────────────────┐
│  [SQL] [JSON] ← Mode Toggle         │
├─────────────────────────────────────┤
│  [Dropdown: Pilih JSON File]        │
│  [+ New] [📁 Browse] [🗑 Delete]    │ ← TOMBOL BARU!
│  📁 JSON Database                   │
└─────────────────────────────────────┘
```

**Tombol "New"** (Hijau):
- Icon: ➕ (Plus)
- Warna: Hijau (success)
- Fungsi: Membuat file JSON baru di folder json_db/

**Tombol "Browse"** (Biru): **NEW!**
- Icon: 📁 (Folder Open)
- Warna: Biru (accent)
- Fungsi: Browse dan pilih file JSON dari mana saja di server

**Tombol "Delete"** (Merah):
- Icon: 🗑️ (Trash)
- Warna: Merah (danger)
- Fungsi: Menghapus file yang sedang aktif
- Hanya muncul jika ada file yang dipilih

---

## 🚀 Cara Menggunakan

### Membuat File JSON Baru

**Langkah 1:** Klik Mode JSON
```
Sidebar → Klik tombol "JSON"
```

**Langkah 2:** Klik "New File"
```
Klik tombol hijau "+ New File"
```

**Langkah 3:** Masukkan Nama File
```
Dialog akan muncul:
┌──────────────────────────────────┐
│  Create New JSON File            │
├──────────────────────────────────┤
│  [mydata.json____________]       │
│  File akan dibuat di json_db/    │
│                                  │
│  [Cancel]  [Create]              │
└──────────────────────────────────┘
```

**Contoh Nama File yang Valid:**
- ✅ `mydata.json`
- ✅ `users.json`
- ✅ `products_2024.json`
- ✅ `backup-data.json`
- ✅ `test_db.json`

**Nama File yang TIDAK Valid:**
- ❌ `mydata` (harus ada .json)
- ❌ `my data.json` (tidak boleh spasi)
- ❌ `data@2024.json` (tidak boleh karakter khusus)
- ❌ `../mydata.json` (tidak boleh path traversal)

**Langkah 4:** Klik "Create"
```
File akan dibuat dan otomatis dipilih
Anda akan diarahkan ke file baru
```

**Langkah 5:** Mulai Gunakan
```
File sudah siap dengan tabel default
Anda bisa:
- Rename tabel default
- Tambah tabel baru
- Insert data
- Dan semua operasi CRUD lainnya
```

---

### Memilih File JSON dari Server (Browse) **NEW!**

**Langkah 1:** Klik Mode JSON
```
Sidebar → Klik tombol "JSON"
```

**Langkah 2:** Klik "Browse"
```
Klik tombol biru "📁 Browse"
```

**Langkah 3:** Navigasi Folder
```
Dialog file browser akan muncul:
┌──────────────────────────────────┐
│  Browse JSON File                │
├──────────────────────────────────┤
│  Current Path: [/___________] Go │
│  ┌────────────────────────────┐  │
│  │ 📁 laragon                 │  │
│  │ 📁 www                     │  │
│  │ 📁 test                    │  │
│  │ 📄 mydata.json    [Select] │  │
│  └────────────────────────────┘  │
│  [Close]                         │
└──────────────────────────────────┘
```

**Cara Navigasi:**
- **Klik folder** untuk masuk ke folder tersebut
- **Klik ".."** untuk kembali ke folder parent
- **Ketik path** di input "Current Path" dan klik "Go" untuk langsung ke path tertentu
- **Klik "Select"** di samping file JSON untuk memilih file tersebut

**Langkah 4:** Pilih File
```
Klik tombol "Select" di samping file yang ingin digunakan
Konfirmasi akan muncul
Klik "Yes, use it!"
```

**Langkah 5:** File Siap Digunakan
```
File akan otomatis dipilih dan tabel akan muncul di sidebar
Anda bisa langsung CRUD data seperti biasa
File eksternal akan tetap di lokasi aslinya (tidak dipindah)
```

---

### Menghapus File JSON

**Langkah 1:** Pilih File yang Ingin Dihapus
```
Dropdown → Pilih file (contoh: old_data.json)
```

**Langkah 2:** Klik "Delete"
```
Klik tombol merah "🗑 Delete"
```

**Langkah 3:** Konfirmasi
```
Dialog konfirmasi akan muncul:
┌──────────────────────────────────┐
│  ⚠️ Delete JSON File?            │
├──────────────────────────────────┤
│  File: old_data.json             │
│  This cannot be undone!          │
│                                  │
│  [Cancel]  [Yes, delete it!]     │
└──────────────────────────────────┘
```

**Langkah 4:** Konfirmasi Hapus
```
Klik "Yes, delete it!"
File akan dihapus permanen
Anda akan diarahkan kembali ke mode JSON
```

---

## 🔒 Keamanan & Validasi

### Validasi Nama File
- ✅ Harus diakhiri dengan `.json`
- ✅ Hanya boleh: huruf (a-z, A-Z), angka (0-9), underscore (_), hyphen (-)
- ✅ Tidak boleh: spasi, karakter khusus (@, #, $, dll)
- ✅ Tidak boleh: path traversal (../, ..\, dll)

### Keamanan Hapus File
- ✅ Konfirmasi sebelum hapus
- ✅ Tidak bisa undo setelah dihapus
- ✅ Session otomatis clear jika file aktif dihapus
- ✅ Menggunakan `basename()` untuk prevent path traversal

### Proteksi File System
- ✅ File hanya bisa dibuat di folder `json_db/`
- ✅ Tidak bisa akses folder lain
- ✅ Validasi ketat untuk nama file

---

## 💡 Tips & Best Practices

### Penamaan File
```
✅ GOOD:
- users.json          (simple, clear)
- products_2024.json  (with year)
- backup-20240304.json (with date)
- test_db.json        (with purpose)

❌ BAD:
- data.json           (too generic)
- temp.json           (unclear purpose)
- 123.json            (no context)
- old.json            (vague)
```

### Organisasi File
```
json_db/
├── users.json           # User data
├── products.json        # Product catalog
├── orders.json          # Order history
├── settings.json        # App settings
└── backup_20240304.json # Backup files
```

### Backup Strategy
1. **Sebelum Edit Besar:**
   ```
   1. Pilih file yang akan diedit
   2. Buat file baru: "backup_[nama]_[tanggal].json"
   3. Copy data dari file original
   4. Edit file original dengan aman
   ```

2. **Backup Berkala:**
   ```
   - Copy file JSON ke folder backup
   - Gunakan version control (Git)
   - Simpan di cloud storage
   ```

3. **Sebelum Hapus:**
   ```
   - Download file dulu
   - Atau copy ke folder backup
   - Pastikan tidak ada data penting
   ```

---

## 🎬 Workflow Contoh

### Scenario 1: Membuat Database Baru untuk Project
```
1. Klik mode JSON
2. Klik "New File"
3. Nama: "project_alpha.json"
4. Create
5. Rename tabel "default_table" → "users"
6. Tambah tabel "tasks"
7. Tambah tabel "projects"
8. Insert data
9. Selesai!
```

### Scenario 2: Testing dengan Data Dummy
```
1. Klik "New File"
2. Nama: "test_data.json"
3. Create
4. Insert data dummy
5. Test aplikasi
6. Setelah selesai → Klik "Delete"
7. Konfirmasi hapus
8. Clean!
```

### Scenario 3: Migrasi dari SQL ke JSON
```
1. Export data dari SQL (format JSON)
2. Klik "New File"
3. Nama: "migrated_data.json"
4. Create
5. Import data yang sudah di-export
6. Verify data
7. Switch ke file baru
```

---

## 🐛 Troubleshooting

### Error: "Invalid filename"
**Penyebab:** Nama file mengandung karakter tidak valid
**Solusi:** Gunakan hanya huruf, angka, underscore, dan hyphen

### Error: "Failed to create file"
**Penyebab:** Permission issue atau folder tidak ada
**Solusi:** 
- Pastikan folder `json_db/` ada
- Cek permission folder (harus writable)
- Cek disk space

### Error: "File not found" saat delete
**Penyebab:** File sudah dihapus atau tidak ada
**Solusi:** Refresh page dan coba lagi

### Tombol "Delete" tidak muncul
**Penyebab:** Tidak ada file yang dipilih
**Solusi:** Pilih file dari dropdown dulu

---

## 📊 Perbandingan: Manual vs UI

| Aspek | Manual (FTP/SSH) | UI (Adminer) |
|-------|------------------|--------------|
| Akses | Perlu FTP/SSH | Cukup browser |
| Kecepatan | Lambat | Cepat |
| Validasi | Manual | Otomatis |
| Safety | Risiko typo | Validasi ketat |
| User Friendly | ❌ | ✅ |
| Backup | Manual copy | Bisa otomatis |

---

## 🎯 Kesimpulan

Dengan fitur JSON File Management ini, Anda bisa:
- ✅ Membuat database JSON baru dalam hitungan detik
- ✅ Menghapus file yang tidak diperlukan dengan aman
- ✅ Mengelola multiple database JSON dengan mudah
- ✅ Tidak perlu akses FTP/SSH
- ✅ Semua dari interface yang user-friendly

**Selamat mencoba!** 🚀

---

## 📝 Changelog

**Version 1.1 (2024-03-04)**
- ✅ Added "New File" button
- ✅ Added "Delete" button
- ✅ Added file validation
- ✅ Added safety confirmations
- ✅ Added auto-redirect after create/delete

**Version 1.0 (2024-03-04)**
- ✅ Initial JSON Database support
- ✅ File selection dropdown
- ✅ CRUD operations

---

**Last Updated:** 2024-03-04
**Feature Status:** ✅ Production Ready
