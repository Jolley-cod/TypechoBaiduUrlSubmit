<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

/**
 * Baidu Url Submit Plugin
 * 将文章URL推送到百度链接提交接口
 * @author jolley
 * @package BaiduUrlSubmit
 * @version 0.0.1
 */
class BaiduUrlSubmit_Plugin implements Typecho_Plugin_Interface
{
    /**
     * 激活插件：挂载文章/页面发布钩子
     */
    public static function activate()
    {
        Typecho_Plugin::factory('Widget_Contents_Post_Edit')->finishPublish = array('BaiduUrlSubmit_Plugin', 'pushToBaidu');
        Typecho_Plugin::factory('Widget_Contents_Page_Edit')->finishPublish = array('BaiduUrlSubmit_Plugin', 'pushToBaidu');
    }

    /**
     * 禁用插件：无需清理操作
     */
    public static function deactivate()
    {
    }

    /**
     * 插件配置面板：添加域名和Token配置
     */
    public static function config(Typecho_Widget_Helper_Form $form)
    {
        // 网站域名（需与百度验证域名一致）
        $site = new Typecho_Widget_Helper_Form_Element_Text(
            'site', 
            NULL, 
            NULL, 
            _t('网站域名'), 
            _t('例：https://www.example.com（需与百度资源平台验证域名完全一致）')
        );
        $form->addInput($site);
        
        // 百度推送Token（从百度资源平台获取）
        $token = new Typecho_Widget_Helper_Form_Element_Text(
            'token', 
            NULL, 
            NULL, 
            _t('百度推送Token'), 
            _t('<a href="https://ziyuan.baidu.com/linksubmit/index" target="_blank">前往百度资源平台获取</a>')
        );
        $form->addInput($token);
    }

    /**
     * 个人配置面板：无需个人配置
     */
    public static function personalConfig(Typecho_Widget_Helper_Form $form)
    {
    }

    /**
     * 核心逻辑：推送URL到百度（统一URL域名避免不匹配）
     */
    public static function pushToBaidu($content, $post)
    {
        $options = Helper::options();
        $site = $options->plugin('BaiduUrlSubmit')->site;
        $token = $options->plugin('BaiduUrlSubmit')->token;
        
        // 检查配置完整性
        if (empty($site) || empty($token)) {
            self::log("配置不完整：域名或Token未填写，无法推送");
            return $content;
        }

        // 统一URL域名：用配置的site域名重构推送URL
        $url = $post->permalink;
        $siteParsed = parse_url($site);
        $siteHost = $siteParsed['host']; // 提取site域名（如www.codyz.cn）
        $siteScheme = $siteParsed['scheme']; // 提取协议（如https）
        
        $urlParsed = parse_url($url);
        $urlPath = $urlParsed['path'] ?? ''; // 提取URL路径（如/archives/1051.html）
        $urlQuery = $urlParsed['query'] ?? ''; // 提取查询参数（可选）
        $urlQueryStr = empty($urlQuery) ? '' : "?{$urlQuery}";
        
        // 重构后的URL（确保与site域名一致）
        $unifiedUrl = "{$siteScheme}://{$siteHost}{$urlPath}{$urlQueryStr}";

        // 执行推送
        self::sendRequest(array($unifiedUrl), $site, $token);
        
        return $content;
    }

    /**
     * 记录运行日志到 log.txt
     */
    private static function log($message)
    {
        $logFile = __DIR__ . '/log.txt';
        $time = date('Y-m-d H:i:s');
        $logEntry = "[{$time}] {$message}" . PHP_EOL;
        // 加锁写入避免并发冲突
        file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
    }

    /**
     * 发送POST请求到百度链接提交API
     */
    private static function sendRequest($urls, $site, $token)
    {
        // 清理site末尾斜线（避免与百度验证域名不匹配）
        $cleanSite = rtrim($site, '/');
        // 构建API请求地址
        $apiUrl = "http://data.zz.baidu.com/urls?site={$cleanSite}&token={$token}";

        // 初始化CURL
        $ch = curl_init();
        $curlOptions = array(
            CURLOPT_URL => $apiUrl,
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS => implode("\n", $urls), // POST内容：每行一个URL
            CURLOPT_HTTPHEADER => array(
                'Content-Type: text/plain', // 百度要求的Content-Type
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36'
            ),
            CURLOPT_TIMEOUT => 10, // 10秒超时
            CURLOPT_SSL_VERIFYPEER => false, // 禁用SSL证书验证
            CURLOPT_SSL_VERIFYHOST => false
        );

        // 执行请求并处理响应
        curl_setopt_array($ch, $curlOptions);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        // 解析结果并记录日志
        $result = json_decode($response, true);
        $urlStr = implode(', ', $urls);
        
        if ($httpCode === 200 && isset($result['success']) && $result['success'] > 0) {
            $remain = isset($result['remain']) ? $result['remain'] : 0;
            self::log("推送百度成功 | URL：{$urlStr} | 状态码：{$httpCode} | 成功数：{$result['success']} | 剩余配额：{$remain}");
        } else {
            $errorMsg = isset($result['message']) ? $result['message'] : '未知错误';
            $errorDetail = empty($curlError) ? '' : " | CURL错误：{$curlError}";
            self::log("推送百度失败 | URL：{$urlStr} | 状态码：{$httpCode} | 错误信息：{$errorMsg}{$errorDetail} | 请求地址：{$apiUrl}");
        }
    }
}