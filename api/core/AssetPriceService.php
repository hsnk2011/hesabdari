<?php
// /api/core/AssetPriceService.php
// Live prices for currency/gold assets from tgju.org. tgju has no official
// API, so the public market pages are read: each instrument is a table row
// marked data-market-row="<key>" carrying data-price="<rial price>".
// Prices are stored in asset_prices (in the app currency, i.e. rial divided
// by currency_rial_divisor). On failure the previous price is kept.

class AssetPriceService {
    /** key => [title, tgju page] */
    const KEYS = [
        'price_dollar_rl' => ['دلار آمریکا (بازار آزاد)', 'currency'],
        'price_eur' => ['یورو', 'currency'],
        'price_aed' => ['درهم امارات', 'currency'],
        'price_gbp' => ['پوند انگلیس', 'currency'],
        'price_try' => ['لیر ترکیه', 'currency'],
        'sekee' => ['سکه امامی', 'coin'],
        'sekeb' => ['سکه بهار آزادی', 'coin'],
        'nim' => ['نیم سکه', 'coin'],
        'rob' => ['ربع سکه', 'coin'],
        'gerami' => ['سکه گرمی', 'coin'],
        'geram18' => ['طلای ۱۸ عیار (هر گرم)', 'gold-chart'],
        'geram24' => ['طلای ۲۴ عیار (هر گرم)', 'gold-chart'],
        'mesghal' => ['مثقال طلا', 'gold-chart'],
    ];

    const BASE_URL = 'https://www.tgju.org/';

    private $conn;

    public function __construct($conn) {
        $this->conn = $conn;
    }

    /**
     * Price (in rial) of each requested key found in an HTML page.
     */
    public static function parsePage($html, array $keys) {
        $found = [];
        foreach ($keys as $key) {
            $pos = strpos($html, 'data-market-row="' . $key . '"');
            if ($pos === false) {
                continue;
            }
            // The row's attributes (incl. a tooltip containing markup) come before data-price.
            $chunk = substr($html, $pos, 8000);
            $rowEnd = strpos($chunk, '</tr>');
            if ($rowEnd !== false) {
                $chunk = substr($chunk, 0, $rowEnd);
            }
            $price = null;
            if (preg_match('/data-price="([\d,٬.]+)"/u', $chunk, $m)) {
                $price = self::toNumber($m[1]);
            }
            if (!$price && preg_match('/<td[^>]*>\s*([\d,٬]+)\s*<\/td>/u', $chunk, $m)) {
                $price = self::toNumber($m[1]);
            }
            if ($price && $price > 0) {
                $found[$key] = $price;
            }
        }
        return $found;
    }

    private static function toNumber($s) {
        $s = strtr($s, ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9', ',' => '', '٬' => '']);
        return is_numeric($s) ? (float)$s : null;
    }

    /**
     * Fetch every page that holds a used (or all) key and store the prices.
     * Returns ['updated' => n, 'errors' => [...]].
     */
    public function refresh($onlyUsed = true) {
        $keys = array_keys(self::KEYS);
        if ($onlyUsed) {
            $used = [];
            $res = $this->conn->query("SELECT DISTINCT price_key FROM assets WHERE price_key IS NOT NULL AND price_key <> ''");
            while ($res && ($row = $res->fetch_assoc())) {
                if (isset(self::KEYS[$row['price_key']])) {
                    $used[] = $row['price_key'];
                }
            }
            if (empty($used)) {
                return ['updated' => 0, 'errors' => []];
            }
            $keys = $used;
        }
        $byPage = [];
        foreach ($keys as $k) {
            $byPage[self::KEYS[$k][1]][] = $k;
        }

        $divisor = (float)get_app_setting($this->conn, 'currency_rial_divisor', '10');
        if ($divisor <= 0) {
            $divisor = 1;
        }
        $updated = 0;
        $errors = [];
        $stmt = $this->conn->prepare(
            "INSERT INTO asset_prices (price_key, title, price, source, fetched_at) VALUES (?, ?, ?, 'tgju', NOW())
             ON DUPLICATE KEY UPDATE title = VALUES(title), price = VALUES(price), source = VALUES(source), fetched_at = VALUES(fetched_at)"
        );
        foreach ($byPage as $page => $pageKeys) {
            $html = $this->fetch(self::BASE_URL . $page);
            if ($html === null) {
                $errors[] = "دریافت صفحه {$page} از tgju ناموفق بود.";
                continue;
            }
            $prices = self::parsePage($html, $pageKeys);
            foreach ($pageKeys as $k) {
                if (!isset($prices[$k])) {
                    $errors[] = "قیمت «" . self::KEYS[$k][0] . "» در صفحه tgju پیدا نشد.";
                    continue;
                }
                $title = self::KEYS[$k][0];
                $price = round($prices[$k] / $divisor, 2);
                $stmt->bind_param('ssd', $k, $title, $price);
                $stmt->execute();
                $updated++;
            }
        }
        if ($errors && class_exists('AppLogger')) {
            AppLogger::warning('Asset price refresh: ' . implode(' | ', $errors), ['source' => 'asset_prices']);
        }
        return ['updated' => $updated, 'errors' => $errors];
    }

    /** Stored prices with titles, plus the list of keys an asset can use. */
    public function getPrices() {
        $stored = [];
        $res = $this->conn->query("SELECT price_key, title, price, source, fetched_at FROM asset_prices");
        while ($res && ($row = $res->fetch_assoc())) {
            $stored[$row['price_key']] = $row;
        }
        $list = [];
        foreach (self::KEYS as $key => [$title]) {
            $list[] = [
                'key' => $key,
                'title' => $title,
                'price' => isset($stored[$key]) ? (float)$stored[$key]['price'] : null,
                'fetched_at' => $stored[$key]['fetched_at'] ?? null,
            ];
        }
        return $list;
    }

    private function fetch($url) {
        if (!function_exists('curl_init')) {
            $ctx = stream_context_create(['http' => ['timeout' => 20, 'header' => "User-Agent: Mozilla/5.0\r\n"]]);
            $body = @file_get_contents($url, false, $ctx);
            return $body === false ? null : $body;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; Hesabdari price reader)',
            CURLOPT_ENCODING => '',
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($body === false || $code !== 200) ? null : $body;
    }
}
