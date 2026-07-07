# Mayar.id Payment Gateway for WooCommerce

Plugin WooCommerce untuk integrasi payment gateway Mayar.id.

## Fitur

- ✅ Virtual Account (Bank Transfer)
- ✅ QRIS
- ✅ E-Wallet (GoPay, OVO, Dana, ShopeePay, LinkAja)
- ✅ Kartu Kredit/Debit (Visa & Mastercard)
- ✅ Retail (Alfamart, Indomaret)
- ✅ Pay Later (Akulaku)
- ✅ Sandbox mode untuk testing
- ✅ Auto webhook registration
- ✅ Idempotent payment processing

## Persyaratan

- WordPress 5.8+
- PHP 7.4+
- WooCommerce 6.0+

## Instalasi

1. Upload folder `mayar-payment-gateway` ke `/wp-content/plugins/`
2. Aktifkan plugin melalui menu **Plugins** di WordPress Admin
3. Masuk ke **WooCommerce → Settings → Payments**
4. Klik **Mayar.id** untuk mengkonfigurasi
5. Masukkan API Key dari Mayar.id
6. Aktifkan gateway

## Konfigurasi

### Mendapatkan API Key

**Sandbox (Testing):**
1. Kunjungi https://web.mayar.club
2. Buat akun atau login
3. Buka https://web.mayar.club/api-keys
4. Buat API Key baru (Read & Write)
5. Copy API Key

**Production:**
1. Kunjungi https://web.mayar.id
2. Buka https://web.mayar.id/api-keys
3. Buat API Key baru (Read & Write)
4. Copy API Key

### Pengaturan Gateway

| Field | Keterangan |
|---|---|
| Enable/Disable | Aktifkan/nonaktifkan gateway |
| Title | Judul yang ditampilkan di checkout (default: Mayar.id) |
| Description | Deskripsi metode pembayaran |
| Sandbox Mode | Aktifkan untuk testing |
| API Key | API Key dari Mayar.id |
| Webhook Secret | Secret untuk verifikasi webhook (opsional) |
| Instructions | Instruksi pembayaran untuk pelanggan |

## Alur Pembayaran

1. Customer checkout → Pilih "Mayar.id"
2. Plugin membuat payment request via Mayar API
3. Customer di-redirect ke halaman Mayar.id
4. Customer memilih metode bayar & menyelesaikan pembayaran
5. Mayar.id mengirim webhook ke WordPress
6. Plugin memverifikasi & update status order
7. Customer di-redirect ke halaman thank-you WooCommerce

## Webhook URL

```
https://yourdomain.com/wp-json/mayar-wc/v1/webhook
```

Webhook akan didaftarkan otomatis saat Anda menyimpan pengaturan gateway.

## Debugging

Log WooCommerce tersedia di **WooCommerce → Status → Logs** dengan source `mayar-wc`.

## License

GPL v2 or later
