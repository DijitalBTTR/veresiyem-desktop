# Sunucu Tarafı Kurulum

## 1. MySQL Veritabanı

```sql
CREATE DATABASE IF NOT EXISTS veresiyem_stats CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'vrm_stats'@'localhost' IDENTIFIED BY 'GUCLU_SIFRE_YAZIN';
GRANT ALL PRIVILEGES ON veresiyem_stats.* TO 'vrm_stats'@'localhost';
FLUSH PRIVILEGES;
```

Tablo `app-ping.php` ilk çalışmada otomatik oluşturulur.

## 2. Dosyaları Sunucuya Yükle

```
/var/www/veresiyem/app-ping.php   ← ping endpoint
/var/www/veresiyem/stats.php      ← admin istatistik paneli
```

## 3. Ortam Değişkenleri (.env veya sunucu env)

```
DB_HOST=localhost
DB_NAME=veresiyem_stats
DB_USER=vrm_stats
DB_PASS=GUCLU_SIFRE_YAZIN
```

PHP'nin bu değişkenleri okuması için Caddyfile'a eklenebilir:

```
veresiyem.com.tr {
    # mevcut site bloğuna ekle:
    php_fastcgi unix//run/php/php8.2-fpm.sock {
        env DB_HOST localhost
        env DB_NAME veresiyem_stats
        env DB_USER vrm_stats
        env DB_PASS GUCLU_SIFRE_YAZIN
    }
}
```

## 4. stats.php Şifresini Değiştir

`stats.php` içindeki:
```php
define('STATS_KEY', 'vrm_stats_2024_gizli');
```
satırını güçlü bir şifreyle güncelleyin.

## 5. İstatistiklere Erişim

```
https://veresiyem.com.tr/stats?key=SIFRENIZ
```

## Notlar

- IP adresleri **ham saklanmaz**, SHA-256 hash'i tutulur (KVKK uyumu).
- `install` event'i her UUID için yalnızca bir kez kaydedilir.
- `open` event'i her uygulama açılışında kaydedilir.
- Electron çevrimdışıysa ping sessizce başarısız olur, uygulama etkilenmez.
