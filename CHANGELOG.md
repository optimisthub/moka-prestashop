# Changelog

## 1.0.2 — Güvenlik güncellemesi

### Güvenlik
- Ödeme callback'i artık sepet/sipariş bağlı, **tek kullanımlık** doğrulama kaydı olmadan kabul edilmiyor. Kayıt yoksa hiçbir hash geçerli sayılmaz (önceden beklenen değer herkesin hesaplayabildiği `sha256("T")` sabitine düşüyordu).
- Hash karşılaştırması sabit zamanlı `hash_equals()` ile yapılıyor.
- Sipariş yalnızca ödeme Moka tarafında sunucu-sunucuya doğrulandıktan sonra oluşturuluyor: `PaymentStatus = 2` **ve** `TrxStatus = 1` (iptal ve ön provizyon durumlarında da `TrxStatus = 1` döner) ve tutar/para birimi eşleşmelidir.
- Aynı sepet için sipariş zaten varsa tekrar oluşturulmuyor; doğrulama kaydı ancak sipariş oluştuktan sonra tüketiliyor.

### Düzeltmeler
- **Çapraz site callback çalışmıyordu:** banka dönüşündeki POST'ta PrestaShop oturum çerezi gönderilmediği için callback "Order Not Found" ile düşüyordu. Doğrulama kaydı veritabanına taşındı ve benzersiz işlem koduyla bulunuyor.
- İşlem kodu (`OtherTrxCode`) her denemede benzersiz üretiliyor; Moka bu alanı bayi bazında kalıcı olarak benzersiz ister.
- Ödeme formunun AJAX adresleri `Link` API'si ile üretiliyor; sabit `/module/moka/checkout` yolu dostu URL kapalı mağazalarda ana sayfaya düşüyor ve form hiç gönderilemiyordu.
- `paymentOptionResult()` içindeki `setAction` var olmayan `validation` denetleyicisini gösteriyordu (404); mevcut `checkout` denetleyicisine çevrildi.
- `moka-php` istemcisine bağlantı (10 sn) ve toplam (60 sn) zaman aşımı eklendi; Moka yanıt vermezse istek sonsuza kadar asılı kalıyordu.
