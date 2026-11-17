# Netgsm SMS API v3 PHP İstemcisi

Netgsm [SMS API v3](https://api-docs.netgsm.com.tr/) için PHP istemcisi. Laravel, Symfony ya da düz PHP
projelerinde kullanılabilir.

> **v2 kullanıyorsanız:** v3.0.0 geriye uyumlu değildir. Mevcut kodunuzu değiştirmek istemiyorsanız
> `composer.json` dosyanızda `"netgsm/sms": "^2.0"` kısıtını koruyun; 2.x sürümleri çalışmaya devam eder.
> Geçiş için [v2'den v3'e geçiş](#v2den-v3e-geçiş) bölümüne bakın.

## Gereksinimler

- PHP 8.1 veya üzeri, `ext-curl` ve `ext-json`
- SMS API v3 erişimi açık bir Netgsm hesabı: abone numarası, API alt kullanıcısı şifresi ve onaylı bir
  gönderici adı (başlık). Hesap ayarları için:
  [Bilgi Bankası, API ile SMS](https://bilgibankasi.netgsm.com.tr/sms/toplu-sms/api-ile-sms)

## Kurulum

```bash
composer require netgsm/sms
```

Kimlik bilgilerini `.env` dosyanızda tutun; koda ya da sürüm kontrolüne yazmayın:

```dotenv
NETGSM_USERNAME=850xxxxxxx     # abone numarası
NETGSM_PASSWORD=               # API alt kullanıcısının şifresi
NETGSM_SENDER=BASLIGINIZ       # onaylı gönderici adı, kayıtlı hâliyle birebir
```

## Hızlı başlangıç

```php
use Netgsm\Sms\NetgsmClient;
use Netgsm\Sms\NetgsmException;

$client = new NetgsmClient(getenv('NETGSM_USERNAME'), getenv('NETGSM_PASSWORD'));

try {
    $result = $client->send(getenv('NETGSM_SENDER'), [
        ['recipient' => '+905321234567', 'text' => 'Siparişiniz kargoya verildi.'],
        ['recipient' => '+905321234568', 'text' => 'Siparişiniz hazırlanıyor.'],
    ], ['encoding' => 'TURKISH']);

    // $result['jobId'] ile rapor sorgulanır.
    foreach ($result['rejectedItems'] as $item) {
        // Geçerli kayıtlar gönderildi; bu kayıt reddedildi.
        error_log("Reddedildi: {$item['recipient']} {$item['reason']}");
    }
} catch (NetgsmException $e) {
    error_log($e->getMessage()); // requestId içerir
}
```

Numaralar E.164 biçiminde yazılır: `+90` ve 10 rakam (`+905321234567`), yurt dışı için `+` ve ülke kodu
(`+4915112345678`).

## Token önbelleği

İstemci, kullanıcı adı ve şifreyle bir kez token alır ve süresi dolana kadar bu token'ı kullanır. Token
alma isteği IP başına dakikada 30 ile sınırlıdır. PHP-FPM'de her web isteği ayrı bir süreç olduğundan,
token'ın süreçler arasında paylaşılması için bir PSR-16 cache verin:

```php
$client = new NetgsmClient($username, $password, $cache); // Psr\SimpleCache\CacheInterface
```

Cache verilmezse token yalnızca nesne yaşadığı sürece bellekte tutulur. Bu, CLI betikleri ve kuyruk
işçileri için yeterlidir.

### Laravel

`App\Providers\AppServiceProvider` içinde:

```php
use Illuminate\Support\Facades\Cache;
use Netgsm\Sms\NetgsmClient;

public function register(): void
{
    $this->app->singleton(NetgsmClient::class, fn () => new NetgsmClient(
        env('NETGSM_USERNAME'),
        env('NETGSM_PASSWORD'),
        Cache::store(),
    ));
}
```

Config cache kullanıyorsanız (`php artisan config:cache`) `env()` çağrılarını `config/services.php`
dosyasına taşıyın ve burada `config('services.netgsm.username')` gibi okuyun.

### Symfony

`config/services.yaml`:

```yaml
services:
    netgsm.token_cache:
        class: Symfony\Component\Cache\Psr16Cache
        arguments: ['@cache.app']

    Netgsm\Sms\NetgsmClient:
        arguments:
            $username: '%env(NETGSM_USERNAME)%'
            $password: '%env(NETGSM_PASSWORD)%'
            $cache: '@netgsm.token_cache'
```

## Metotlar

Bütün metotlar API'nin döndürdüğü JSON'u dizi olarak döndürür. Alanların açıklaması her metodun
yanındaki resmî doküman sayfasındadır.

| Metot | Uç nokta | Doküman |
|---|---|---|
| `send($sender, $messages, $options)` | `POST /sms/v3/send` | [send-sms](https://api-docs.netgsm.com.tr/send-sms/) |
| `cancel($jobId)` | `POST /sms/v3/cancel` | [cancel-job](https://api-docs.netgsm.com.tr/cancel-job/) |
| `report($jobIds, $startAt, $stopAt, $page)` | `GET /sms/v3/report` | [read-reports](https://api-docs.netgsm.com.tr/read-reports/) |
| `inbox($startAt, $stopAt)` | `GET /sms/v3/inbox` | [read-inbox](https://api-docs.netgsm.com.tr/read-inbox/) |
| `senders()` | `GET /sms/v3/senders` | [list-senders](https://api-docs.netgsm.com.tr/list-senders/) |
| `blacklist($numbers, $page, $pageTotal)` | `GET /sms/v3/blacklist` | [kara-liste](https://api-docs.netgsm.com.tr/kara-liste/) |
| `addToBlacklist($numbers)` | `POST /sms/v3/blacklist` | [kara-liste](https://api-docs.netgsm.com.tr/kara-liste/) |
| `removeFromBlacklist($numbers)` | `DELETE /sms/v3/blacklist` | [kara-liste](https://api-docs.netgsm.com.tr/kara-liste/) |
| `balance($stip, $service)` | `GET /sms/v3/balance` | [get-balance](https://api-docs.netgsm.com.tr/get-balance/) |

### Gönderim

Bütün alıcılar tek `send()` çağrısında gönderilir; alıcı başına ayrı çağrı yapmayın. Bir istek 1.000.000
kayda kadar taşır ve her kaydın kendi metni olabilir.

```php
$client->send('BASLIGINIZ', $messages, [
    'encoding'    => 'TURKISH',                       // DEFAULT, TURKISH, UNICODE
    'iysFilter'   => 'BRAND',                         // ticari iletide İYS filtresi; bilgilendirmede NONE
    'startAt'     => new DateTimeImmutable('+1 hour'),
    'stopAt'      => new DateTimeImmutable('+5 hours'),
    'referenceId' => 'siparis-4711',
]);
```

Yanıttaki `status` `SCHEDULED` ise görev başlangıç zamanına kadar `cancel()` ile iptal edilebilir;
`QUEUED` ise gönderim başlamıştır.

### Rapor

```php
$tz = new DateTimeZone('Europe/Istanbul');
$page = $client->report([$jobId], new DateTimeImmutable('today', $tz), new DateTimeImmutable('now', $tz));
```

Sayfa başına en fazla 100 satır döner. Aynı görev dakikada bir sorgulanabilir. Raporu henüz gelmemiş
alıcılar listede yer almaz.

### Gelen SMS

```php
$inbox = $client->inbox(new DateTimeImmutable('-1 day'), new DateTimeImmutable());
```

Tarih verilmezse içinde bulunulan ay döner. Yanıttaki `sender` alanı şu an yurt içi numaraları `90` öneki
olmadan döndürür (`5321234567`).

### Kara liste

```php
$client->blacklist();                              // liste, sayfa 0, sayfa başına 200
$client->blacklist(['+905321234567']);             // numara listede mi
$client->addToBlacklist(['+905321234567']);
$client->removeFromBlacklist(['+905321234567']);
```

Ekleme ve silme istek başına en fazla 100 numara alır.

### Bakiye

```php
$balance = $client->balance(3);
```

Büyük bir gönderimden önce bir kez sorgulayın; her mesajdan önce sorgulamayın. `Kredi Bakiye` değeri
virgüllü bir metindir (`"92,150"`, yani 92,15 TL).

## Hata yönetimi

API hata döndürdüğünde ya da yanıt alınamadığında `Netgsm\Sms\NetgsmException` fırlatılır.

| Alan | Açıklama |
|---|---|
| `status` | HTTP durum kodu. Yanıt alınamadıysa `0`. |
| `errorCode` | API'nin hata kodu: `REJECTED_HEADER`, `INVALID_REQUEST`, `RATE_LIMITED`, `invalid_credentials` vb. Yanıt alınamadıysa `NETWORK_ERROR`. |
| `requestId` | Destek talebinde paylaşılacak istek kimliği. |
| `retryAfter` | 429 yanıtında kaç saniye sonra tekrar denenebileceği. |
| `mayHaveBeenQueued` | Gönderimde sunucu hatası ya da bağlantı hatası olduysa `true`. |
| `violations` | 400 yanıtında hangi alanın neden reddedildiği. |
| `rejectedItems` | 422 `NO_DELIVERABLE_MESSAGES` yanıtında reddedilen kayıtlar ve nedenleri. |

Hata mantığını HTTP durum koduna değil `errorCode` alanına göre kurun. Kodların tamamı ve anlamları:
[api-docs.netgsm.com.tr](https://api-docs.netgsm.com.tr/).

```php
try {
    $client->send($sender, $messages);
} catch (NetgsmException $e) {
    if ($e->mayHaveBeenQueued) {
        // Gönderim oluşmuş olabilir. Aynı isteği tekrar göndermek ikinci bir gönderim oluşturur;
        // önce report() ile kontrol edin.
    } elseif ($e->errorCode === 'RATE_LIMITED' && $e->retryAfter !== null) {
        // $e->retryAfter saniye sonra tekrar deneyin.
    } elseif ($e->errorCode === 'RATE_LIMITED') {
        // Retry-After yok: hesabın gönderimi askıya alınmış. Beklemek işe yaramaz, destek ekibine başvurun.
    } else {
        // 400, 403, 422: istek ya da hesap düzeltilmeden tekrar denemeyin.
    }
}
```

İstemci yalnızca bir durumda kendiliğinden tekrar dener: token geçersiz olduğunda (401) bir kez yeniden
giriş yapar ve isteği bir kez tekrarlar. Başka hiçbir isteği tekrarlamaz ve beklemez.

## Güvenlik

- Şifre yalnızca token alırken HTTPS üzerinden istek gövdesinde gönderilir. Diğer isteklerde
  `Authorization: Bearer` başlığı kullanılır.
- TLS sertifika doğrulaması her zaman açıktır ve kapatılamaz. Sunucunuzun CA paketi eskiyse güncelleyin
  ya da bir CA paketi yolu verin:

  ```php
  use Netgsm\Sms\Http\CurlTransport;

  $client = new NetgsmClient($username, $password, $cache, NetgsmClient::BASE_URL, new CurlTransport(caBundle: '/etc/ssl/cacert.pem'));
  ```

- `http://` adresi yalnızca yerel test sunucusu (`127.0.0.1`, `localhost`) için kabul edilir.
- Şifre ve token, hata mesajlarına ve `var_dump()` / `print_r()` çıktısına girmez.

## Test

Gerçek hesabınızı kullanmadan denemek için Netgsm'in SMS göndermeyen sandbox ortamını kullanabilirsiniz:

```php
$client = new NetgsmClient('8500000000', '<sandbox şifresi>', null, 'https://ext-sandbox.netgsm.com.tr');
```

Demo hesaplar ve şifreler:
[SMS API v3 sandbox](https://api-docs.netgsm.com.tr/sandbox/sms-api-v-3-sandbox/).

Paketin kendi testleri:

```bash
composer install
composer test
```

## v2'den v3'e geçiş

v3, Netgsm'in eski API'si yerine SMS API v3'ü kullanır. Sınıf, metotlar, parametreler ve dönüş biçimi
değişti. v2'de kalmak isteyenler `"netgsm/sms": "^2.0"` kısıtını korur.

| v2 | v3 |
|---|---|
| `new SmsSend()` (`$_ENV` okur) | `new NetgsmClient($username, $password, $cache)` |
| `NETGSM_USERCODE`, `NETGSM_PASSWORD` | Abone numarası ve API alt kullanıcısı şifresi, constructor'a verilir |
| `NETGSM_HEADER` | `send()` metodunun ilk parametresi (`$sender`) |
| `smsGonder()` (1:n), `smsGonderNN()` (n:n), `smsgonder1_1()` | `send()`: her kayıt `['recipient' => ..., 'text' => ...]` |
| `smsSorgulama()` | `report()` |
| `gelensms()` | `inbox()` |
| `basliksorgu()` | `senders()` |
| `karaliste()` ekleme / çıkarma | `addToBlacklist()` / `removeFromBlacklist()`; sorgu için `blacklist()` |
| — | `cancel()`, `balance()` |

Parametreler:

| v2 | v3 |
|---|---|
| `no`, `gsm` (`5xx`, `05xx`, `905xx`) | `recipient`, E.164: `+905321234567` |
| `encoding` = `TR` | `encoding` = `TURKISH` |
| `startdate`, `stopdate` (`ddMMyyyyHHmm`) | `startAt`, `stopAt` (`DateTimeInterface`) |
| `filter` | `iysFilter`: `NONE`, `BRAND`, `RETAILER`, `BRAND_AND_RETAILER` |
| `bayikodu` | `partnerCode` |
| `bulkid` | `jobId` |

Davranış farkları:

- **Görev numarası:** `jobId` en fazla 64 karakterlik bir metindir (ör. `179309912974612345062827`).
  `bulkid` değerini sayı kolonunda (`INT`, `BIGINT`) tutuyorsanız kolonu metne çevirin.
- **Dönüş biçimi:** Sonuçlar artık `code` / `aciklama` dizisi değildir. Başarılı yanıt API'nin JSON'udur,
  hatalar `NetgsmException` olarak gelir.
- **Kısmi gönderim:** Hatalı numaralar ayıklanır, geçerli olanlar gönderilir. Reddedilenler
  `rejectedItems` içinde döner.
- **Gelen SMS:** v2'de tarih vermeden sorgu yalnızca okunmamış mesajları bir kez listeliyordu. v3'te tarih
  verilmezse içinde bulunulan ayın bütün mesajları döner.
- **Rapor sınırı:** Aynı görev dakikada bir sorgulanabilir. Sürekli takip için rapor webhook'u
  önerilir: [register-report-webhook](https://api-docs.netgsm.com.tr/register-report-webhook/).

## Destek

- API dokümanı: https://api-docs.netgsm.com.tr/
- Bilgi Bankası: https://bilgibankasi.netgsm.com.tr/
- Teknik destek: teknikdestek@netgsm.com.tr (hata bildirirken `requestId` değerini ekleyin)

## Lisans

MIT
