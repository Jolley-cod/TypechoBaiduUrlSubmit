<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

/**
 * 将文章URL推送到百度的链接提交接口
 * @author jolley
 * @package BaiduUrlSubmit
 * @version 0.0.1
 */
class BaiduUrlSubmit_Plugin implements Typecho_Plugin_Interface
{
    /**
     * 激活插件方法：挂载文章/页面发布钩子
     *
     * @access public
     * @return void
     */
    public static function activate()
    {
        // 挂载文章发布完成钩子
        Typecho_Plugin::factory('Widget_Contents_Post_Edit')->finishPublish = array('BaiduUrlSubmit_Plugin', 'pushToBaidu');
        // 挂载页面发布完成钩子
        Typecho_Plugin::factory('Widget_Contents_Page_Edit')->finishPublish = array('BaiduUrlSubmit_Plugin', 'pushToBaidu');
    }


    /**
     * 插件配置面板：添加网站域名和百度Token配置项
     *
     * @access public
     * @param Typecho_Widget_Helper_Form $form 配置面板对象
     * @return void
     */
    public static function config(Typecho_Widget_Helper_Form $form)
    {
        // 网站域名配置项
        $site = new Typecho_Widget_Helper_Form_Element_Text(
            'site', 
            NULL, 
            NULL, 
            _t('网站域名'), 
            _t('输入你的网站域名，例如：https://www.test.com（需与百度资源平台验证域名完全一致）')
        );
        $form->addInput($site);
        
        // 百度推送Token配置项
        $token = new Typecho_Widget_Helper_Form_Element_Text(
            'token', 
            NULL, 
            NULL, 
            _t('百度推送Token'), 
            _t('输入你的百度链接提交Token。<a href="https://ziyuan.baidu.com/linksubmit/index" target="_blank">前往百度资源平台获取</a>')
        );
        $form->addInput($token);
    }



    /**
     * 推送文章/页面URL到百度链接提交接口
     *
     * @param string $content 文章/页面内容
     * @param Typecho_Widget_Contents_Post $post 文章/页面对象
     * @return string 原内容（钩子需返回处理后的内容）
     */
    public static function pushToBaidu($content, $post)
    {
        $options = Helper::options();
        $site = $options->plugin('BaiduUrlSubmit')->site;
        $token = $options->plugin('BaiduUrlSubmit')->token;
        
        // 检查配置是否完整
        if (empty($site) || empty($token)) {
            self::log("配置不完整：网站域名或百度Token未填写，无法推送URL到百度");
            return $content;
        }
        
        // 获取文章/页面的完整 permalink
        $url = $post->permalink;
        // 调用发送请求方法推送URL
        self::sendRequest(array($url), $site, $token);
        
        // 返回原内容（钩子必须返回内容，否则会导致文章内容为空）
        return $content;
    }

    /**
     * 记录插件运行日志到 log.txt
     *
     * @param string $message 日志内容
     * @return void
     */
    private static function log($message)
    {
        $logFile = __DIR__ . '/log.txt';
        $time = date('Y-m-d H:i:s');
        $logEntry = "[{$time}] {$message}" . PHP_EOL;
        
        // 写入日志（加锁避免并发写入冲突）
        file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
    }

    /**
     * 发送HTTP POST请求到百度链接提交API
     *
     * @param array $urls 待提交的URL数组（单次建议不超过100条）
     * @param string $site 网站域名（与百度验证一致）
     * @param string $token 百度推送Token
     * @return void
     */
    private static function sendRequest($urls, $site, $token)
    {
        // 1. 清理网站域名：去除末尾多余的斜线（避免与百度验证域名不匹配）
        $cleanSite = rtrim($site, '/');
        
        // 2. 构建百度API请求地址（site参数无需URL编码，与手动请求保持一致）
        $apiUrl = "http://data.zz.baidu.com/urls?site={$cleanSite}&token={$token}";
        
        // 3. 初始化CURL请求
        $ch = curl_init();
        $curlOptions = array(
            CURLOPT_URL => $apiUrl,
            CURLOPT_POST => true,                  // 启用POST方法
            CURLOPT_RETURNTRANSFER => true,        // 要求返回响应内容
            CURLOPT_POSTFIELDS => implode("\n", $urls),  // POST内容：URL列表（每行一个）
            CURLOPT_HTTPHEADER => array(           // 设置请求头
                'Content-Type: text/plain',        // 百度要求的Content-Type
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36'
            ),
            CURLOPT_TIMEOUT => 10,                 // 超时时间（10秒）
            CURLOPT_SSL_VERIFYPEER => false,       // 禁用SSL证书验证（避免服务器证书问题）
            CURLOPT_SSL_VERIFYHOST => false        // 禁用SSL主机验证
        );
        
        // 4. 设置CURL选项并执行请求
        curl_setopt_array($ch, $curlOptions);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);  // 获取HTTP状态码
        $curlError = curl_error($ch);                       // 获取CURL错误信息
        curl_close($ch);                                    // 关闭CURL会话

        // 5. 解析响应结果并记录日志
        $result = json_decode($response, true);
        $urlStr = implode(', ', $urls);
        
        if ($httpCode === 200 && isset($result['success']) && $result['success'] > 0) {
            // 推送成功
            $remain = isset($result['remain']) ? $result['remain'] : 0;
            self::log("推送百度成功 | URL：{$urlStr} | 状态码：{$httpCode} | 成功数：{$result['success']} | 剩余配额：{$remain}");
        } else {
            // 推送失败：拼接错误信息
            $errorMsg = isset($result['message']) ? $result['message'] : '未知错误';
            $errorDetail = empty($curlError) ? '' : " | CURL错误：{$curlError}";
            self::log("推送百度失败 | URL：{$urlStr} | 状态码：{$httpCode} | 错误信息：{$errorMsg}{$errorDetail} | 请求地址：{$apiUrl}");
        }
    }
}