<?php

/**
 * FriendCircle 主题 IP 归属地库（ip2region xdb，IPv4 + IPv6）
 *
 * 数据库文件独立存放于主题 assets/ipdb/（ip2region.xdb 为 IPv4 库，
 * ip2region_v6.xdb 为 IPv6 库），不依赖任何插件。
 * 首次开启「插入位置」主题设置时由 functions.php 在后台自动下载；
 * 也可手动下载 https://github.com/lionsoul2014/ip2region 的 data/ 目录下
 * 对应文件覆盖。
 *
 * search($ip) 返回「国家|省州|城市|运营商|国家代码」
 * （如 中国|浙江省|杭州市|阿里|CN），format() 负责转为「江西·赣州」
 * 展示格式（与原「所在位置」字段约定一致）：国内精确到省市（直辖市只显示市），
 * 国外仅国家，内网/未收录返回空。
 */

if (!defined('__FC_IPDB_DIR__')) {
    define('__FC_IPDB_DIR__', __DIR__ . '/assets/ipdb');
}

class FC_IpDb
{
    /** @var array<string, array> 下载源（境内镜像优先——境内部署访问 GitHub raw 常长期挂起；已实测 2026-10 官方 data/ 目录命名） */
    private static $sources = [
        'v4' => [
            'https://ghproxy.net/https://github.com/lionsoul2014/ip2region/raw/master/data/ip2region_v4.xdb',
            'https://gh-proxy.com/https://github.com/lionsoul2014/ip2region/raw/master/data/ip2region_v4.xdb',
            'https://cdn.jsdelivr.net/gh/lionsoul2014/ip2region@master/data/ip2region_v4.xdb',
            'https://github.com/lionsoul2014/ip2region/raw/master/data/ip2region_v4.xdb',
        ],
        'v6' => [
            'https://ghproxy.net/https://github.com/lionsoul2014/ip2region/raw/master/data/ip2region_v6.xdb',
            'https://gh-proxy.com/https://github.com/lionsoul2014/ip2region/raw/master/data/ip2region_v6.xdb',
            'https://github.com/lionsoul2014/ip2region/raw/master/data/ip2region_v6.xdb',
        ],
    ];

    /** @var string 各版本库的校验用测试 IP（公网地址） */
    private static $probeIps = [
        'v4' => '114.114.114.114',
        'v6' => '2400:3200::1',
    ];

    /** @var string 数据库文件路径 */
    public static function path($version = 'v4')
    {
        return __FC_IPDB_DIR__ . ('v6' === $version ? '/ip2region_v6.xdb' : '/ip2region.xdb');
    }

    public static function exists($version = 'v4')
    {
        $file = self::path($version);
        /* v4 约 11MB、v6 约 37MB，小于 1MB 视为不完整，触发重新下载 */
        return is_file($file) && filesize($file) > 1048576;
    }

    /**
     * 向 admin 页面注入一次性下载触发脚本（由 functions.php 的 footer 钩子调用）
     * @return void
     */
    public static function injectDownloader()
    {
        $endpoint = json_encode(\Widget\Options::alloc()->themeUrl . '/ipdb-download.php');
        echo '<script>(function(){';
        echo 'fetch(' . $endpoint . ',{method:"POST"})';
        echo '.then(function(r){return r.json()})';
        echo '.then(function(d){if(!d.ok){alert("IP 归属地数据库下载失败：" + (d.message || "未知错误"));}'
            . 'else{console.log("[FriendCircle] " + d.message);}})';
        echo '.catch(function(e){console.warn("[FriendCircle] 数据库下载请求失败", e)});';
        echo '})();</script>';
    }

    /**
     * 确保 IPv4 库可用（缺失时自动下载并校验）
     * @return true|string true 表示可用，否则为错误信息
     */
    public static function ensure()
    {
        if (self::exists('v4')) {
            return true;
        }
        return self::download('v4');
    }

    /**
     * 确保 IPv6 库可用；下载失败不阻塞 IPv4（IPv6 查询降级为空）
     * @return true|string
     */
    public static function ensureV6()
    {
        if (self::exists('v6')) {
            return true;
        }
        return self::download('v6');
    }

    /**
     * 下载指定版本数据库并校验落盘
     * @return true|string
     */
    private static function download($version)
    {
        if (!is_dir(__FC_IPDB_DIR__)) {
            @mkdir(__FC_IPDB_DIR__, 0755, true);
        }

        $tmp = self::path($version) . '.tmp';
        $context = stream_context_create(['http' => ['timeout' => 45, 'follow_location' => 1]]);
        $lastError = '';

        foreach (self::$sources[$version] as $url) {
            $data = @file_get_contents($url, false, $context);
            if (false === $data || strlen($data) < 1048576) {
                $lastError = $url;
                continue;
            }
            if (false === @file_put_contents($tmp, $data)) {
                return '无法写入主题 assets/ipdb/ 目录，请检查目录权限';
            }
            /* 完整性验证：能正常解析公网 IP 才算下载成功（错误 URL 会在此被拒并尝试下一源） */
            $check = self::verify($version, $tmp);
            if (true !== $check) {
                @unlink($tmp);
                $lastError = (string) $check;
                continue;
            }
            if (!@rename($tmp, self::path($version))) {
                return '数据库校验通过但落盘失败，请检查目录权限';
            }
            return true;
        }

        return '下载数据库失败（' . $lastError . '），可手动下载 ip2region'
            . ('v6' === $version ? '_v6' : '') . '.xdb 放入主题 assets/ipdb/ 目录';
    }

    /**
     * 校验 xdb 文件可用性（对应版本的公网 IP 能解析出内容）
     * @return true|string
     */
    private static function verify($version, $file)
    {
        try {
            require_once __FC_IPDB_DIR__ . '/xdb-search.php';
            $ns = '\FriendCircle\ipdb\\' . ('v6' === $version ? 'IPv6' : 'IPv4');
            $searcher = \FriendCircle\ipdb\Searcher::newWithBuffer(
                $ns::default(),
                file_get_contents($file)
            );
            $region = $searcher->search(self::$probeIps[$version]);
            $searcher->close();
            return ('' !== $region && null !== $region) ? true : 'xdb 文件校验失败（无查询结果）';
        } catch (\Throwable $e) {
            return 'xdb 文件校验失败：' . $e->getMessage();
        }
    }

    /**
     * 查询 IP 归属地原始信息（按地址类型自动选择 v4 / v6 库）
     *
     * xdb 字段串为 5 段：国家|省州|城市|运营商|国家代码
     * （例：中国|浙江省|杭州市|阿里|CN），早期数据可能仅 3~4 段，按段数自适应。
     *
     * @return array|null [country, province, city] 或 null（内网 / 未收录 / 库不可用）
     */
    public static function lookup($ip)
    {
        $bytes = @inet_pton((string) $ip);
        if (false === $bytes || ('' === $ip)) {
            return null;
        }
        $version = 16 === strlen($bytes) ? 'v6' : 'v4';
        if (!self::exists($version)) {
            /* IPv6 库缺失时尽力补下载一次（约 37MB，耗时较长），失败仅影响 IPv6 查询 */
            if ('v6' === $version && true !== self::ensureV6()) {
                return null;
            }
            if (!self::exists($version)) {
                return null;
            }
        }

        try {
            require_once __FC_IPDB_DIR__ . '/xdb-search.php';
            $ns = '\FriendCircle\ipdb\\' . ('v6' === $version ? 'IPv6' : 'IPv4');
            $searcher = \FriendCircle\ipdb\Searcher::newWithBuffer(
                $ns::default(),
                file_get_contents(self::path($version))
            );
            $region = (string) $searcher->search($ip);
            $searcher->close();
        } catch (\Throwable $e) {
            return null;
        }

        if ('' === $region) {
            return null;
        }

        /* 归一化：0、空串、Reserved（文档/保留段）一律视为无数据 */
        $clean = function ($v) {
            $v = trim((string) $v);
            return ('' === $v || '0' === $v || 'Reserved' === $v) ? '' : $v;
        };
        $parts = array_map($clean, explode('|', $region));

        return [
            'country' => isset($parts[0]) ? $parts[0] : '',
            'province' => isset($parts[1]) ? $parts[1] : '',
            'city' => isset($parts[2]) ? $parts[2] : '',
        ];
    }

    /**
     * 查询并格式化为「江西·赣州」展示格式
     * @return string
     */
    public static function format($ip)
    {
        $info = self::lookup($ip);
        if (null === $info || '' === $info['country']) {
            return '';
        }

        /* 国内省市精简后缀；国外仅国家 */
        if ('中国' === $info['country']) {
            $region = preg_replace('/(省|自治区|特别行政区|市|盟|州|地区)$/u', '', $info['province']);
            $city = preg_replace('/(市|盟|州|地区)$/u', '', $info['city']);
            $parts = [];
            if ('' !== (string) $region) {
                $parts[] = $region;
            }
            if ('' !== (string) $city && (string) $city !== (string) $region) {
                $parts[] = $city;
            }
            return implode('·', $parts);
        }

        return $info['country'];
    }
}
