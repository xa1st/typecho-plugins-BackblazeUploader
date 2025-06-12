<?php
namespace TypechoPlugin\BackblazeUploader;

use Typecho\Plugin\PluginInterface;
use Typecho\Plugin\Exception;
use Typecho\Widget\Helper\Form;
use Typecho\Widget\Helper\Form\Element\Text;
use Typecho\Widget\Helper\Form\Element\Password;
use Typecho\Http\Client;
use Typecho\Common;
use Utils\Helper;
use Widget\Upload;


// 防止直接运行
if (!defined('__TYPECHO_ROOT_DIR__'))  exit;

/**
 * 将Typecho附件上传至Backblaze B2存储
 * 
 * @package BackblazeUploader
 * @author 猫东东
 * @version 1.1.0
 * @link https://github.com/xa1st
 */

class Plugin implements PluginInterface {
    /**
    * 激活插件方法,如果激活失败,直接抛出异常
    * 
    * @return void
    * @throws Typecho_Plugin_Exception
    */
    public static function activate() {
        \Typecho\Plugin::factory('Widget_Upload')->uploadHandle = [__CLASS__, 'uploadHandle'];
        \Typecho\Plugin::factory('Widget_Upload')->modifyHandle = [__CLASS__, 'modifyHandle'];
        \Typecho\Plugin::factory('Widget_Upload')->deleteHandle = [__CLASS__, 'deleteHandle'];
        \Typecho\Plugin::factory('Widget_Upload')->attachmentHandle = [__CLASS__, 'attachmentHandle'];
        return _t('插件已启用，请前往设置页面配置Backblaze B2账号信息');
    }
  
    /**
    * 禁用插件方法,如果禁用失败,直接抛出异常
    * 
    * @return void
    * @throws Typecho_Plugin_Exception
    */
    public static function deactivate() {
        return _t('插件已禁用');
    }
  
    /**
    * 获取插件配置面板
    * 
    * @param Typecho_Widget_Helper_Form $form 配置面板
    * @return void
    */
    public static function config(Form $form) {
        $keyId = new Text('keyId',  null,  '',  _t('应用密钥ID'),  _t('Backblaze B2的应用密钥ID'));
        $form->addInput($keyId->addRule('required', _t('必须填写应用密钥ID')));
    
        $applicationKey = new Password('applicationKey',  null,  '',  _t('应用密钥'),  _t('Backblaze B2的应用密钥') );
        $form->addInput($applicationKey->addRule('required', _t('必须填写应用密钥')));
    
        $bucketId = new Text('bucketId',  null,  '',  _t('存储桶ID'),  _t('Backblaze B2的存储桶ID') );
        $form->addInput($bucketId->addRule('required', _t('必须填写存储桶ID')));
    
        $bucketName = new Text('bucketName', null, '', _t('存储桶名称'), _t('Backblaze B2的存储桶名称'));
        $form->addInput($bucketName->addRule('required', _t('必须填写存储桶名称')));
    
        $domain = new Text('domain', null, '', _t('自定义域名'), _t('如果您使用了自定义域名，请填写您的域名，例如：https://typecho.com，不包含尾部斜杠'));
        $form->addInput($domain);
    
        $path = new Text('path', null, 'typecho/', _t('存储路径'), _t('文件存储在存储桶中的路径前缀，以/结尾，例如：typecho/'));
        $form->addInput($path);
    }
  
    /**
    * 个人用户的配置面板
    * 
    * @param Typecho_Widget_Helper_Form $form
    * @return void
    */
    public static function personalConfig(Form $form) {
        // 暂无个人配置项
    }
  
    /**
    * 上传文件处理函数
    * 
    * @param array $file 上传的文件
    * @return array|bool
    */
    public static function uploadHandle($file) {
        // 获取上传文件
        if (empty($file['name'])) return false;    
        // 校验扩展名
        $ext = self::getSafeName($file['name']);
        // 验证可上传文件类型
        if (!Upload::checkFileType($ext)) return false;
        // 获取插件配置
        $options = Helper::options()->plugin('BackblazeUploader');
        // 生成文件名
        $filePath = date('Y/md/');
        $fileName = sprintf('%u', crc32(uniqid())) . '.' . $ext;
        $path = $options->path . $filePath;
        // 上传文件
        $result = self::uploadToBackblaze($file['tmp_name'], $path . $fileName);
        // 返回错误
        if (!$result) return false;
        // 返回相对存储路径
        return ['name' => $file['name'], 'path' => $filePath . $fileName, 'size' => $file['size'], 'type' => $file['type'], 'mime' => @Common::mimeContentType($file['tmp_name'])];
    }
  
    /**
    * 修改文件处理函数
    * 
    * @param array $content 文件相关信息
    * @param string $file 文件完整路径
    * @return string|bool
    */
    public static function modifyHandle($content, $file) {
        // 如果不存在附件，直接返回
        if (!isset($content['attachment'])) return false;    
        // 获取插件配置
        $options = Helper::options()->plugin('BackblazeUploader');
        // 生成文件名
        $ext = self::getSafeName($content['name']);
        $filePath = date('Y/md/');
        $fileName = sprintf('%u', crc32(uniqid())) . '.' . $ext;
        $path = $options->path . $filePath;
        // 上传文件
        $result = self::uploadToBackblaze($file, $path . $fileName);
        if (!$result) return false;
        // 删除旧文件
        self::deleteFile($content['attachment']->path);
        // 返回相对存储路径
        return ['name' => $content['name'], 'path' => $filePath . $fileName, 'size' => $content['size'], 'type' => $content['type'], 'mime' => $content['mime']];
    }
  
    /**
    * 删除文件
    * 
    * @param string $path 文件路径
    * @return bool
    */
    public static function deleteHandle(array $content) {
        if (!isset($content['attachment'])) return false;    
        return self::deleteFile($content['attachment']->path);
    }
  
    /**
    * 获取实际文件网址
    * 
    * @param array $content 文件相关信息
    * @return string
    */
    public static function attachmentHandle(array $content) {
        // 获取插件配置
        $options = Helper::options()->plugin('BackblazeUploader');
        // 生成完整的文件URL
        if (!empty($options->domain)) {
            // 如果自定义了域名
            $url = $options->domain . '/' . $options->path . $content['attachment']->path;
        } else {
            // 使用Backblaze默认域名
            $url = 'https://f002.backblazeb2.com/file/' . $options->bucketName . '/' .  $options->path . $content['attachment']->path;
        }
        return $url;
    }
  
    /**
    * 获取安全的文件名
    * 
    * @param string $name 文件名
    * @return string
    */
    private static function getSafeName(string $name): string {
        $name = str_replace(['"', '<', '>'], '', $name);
        $name = str_replace('\\', '/', $name);
        $name = mb_convert_encoding($name, 'UTF-8', 'UTF-8');
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        return $ext;
    }
  
    /**
    * 上传文件到Backblaze B2
    * 
    * @param string $file 本地文件路径
    * @param string $target 目标存储路径
    * @return bool
    */
    private static function uploadToBackblaze(string $file, string $target): bool {
        // 获取插件配置
        $options = Helper::options()->plugin('BackblazeUploader');
        // 获取授权信息
        $auth = self::getBackblazeAuth($options->keyId, $options->applicationKey);
        // 授权失败，直接返回
        if (!$auth) return false;
    
        // 获取上传URL和授权令牌
        $uploadAuth = self::getUploadUrl($auth, $options->bucketId);
        if (!$uploadAuth) return false;
    
        // 上传文件
        $fileContent = file_get_contents($file);
        $sha1 = sha1_file($file);
        $contentType = Common::mimeContentType($file);
    
        try {
            $client = Client::get();
            $client->setHeader('Authorization', $uploadAuth['authorizationToken'])
                ->setHeader('X-Bz-File-Name', urlencode($target))
                ->setHeader('Content-Type', $contentType)
                ->setHeader('X-Bz-Content-Sha1', $sha1)
                ->setHeader('X-Bz-Info-Author', 'BackblazeUploader')
                ->setData($fileContent)
                ->setMethod(Client::METHOD_POST)
                ->send($uploadAuth['uploadUrl']);
            return $client->getResponseStatus() === 200;
        } catch (Exception $e) {
            error_log($e->getMessage());
            return false;
        }
    }
  
    /**
    * 删除Backblaze B2上的文件
    * 
    * @param string $path 文件路径
    * @return bool
    */
    private static function deleteFile(string $path): bool {
        // 获取插件配置
        $options = Helper::options()->plugin('BackblazeUploader');
        // 获取授权信息
        $auth = self::getBackblazeAuth($options->keyId, $options->applicationKey);
        if (!$auth) return false;
    
        // 获取文件信息
        $fileName = $options->path . $path;
        $fileId = self::getFileId($auth, $options->bucketName, $fileName);
        if (!$fileId) return false; // 文件不存在，视为删除成功
        // 删除文件
        try {
            $client = Client::get();
            $client->setHeader('Authorization', $auth['authorizationToken'])
                ->setHeader('Content-Type', 'application/json')
                ->setData(json_encode(['fileName' => $fileName, 'fileId' => $fileId]))
                ->setMethod(Client::METHOD_POST)
                ->send($auth['apiUrl'] . '/b2api/v2/b2_delete_file_version');
            return $client->getResponseStatus() === 200;
        } catch (Exception $e) {
            error_log($e->getMessage());
            return false;
        }
    }
  
    /**
    * 获取Backblaze B2认证信息
    * 
    * @param string $keyId 应用密钥ID
    * @param string $applicationKey 应用密钥
    * @return array|bool
    */
    private static function getBackblazeAuth(string $keyId, string $applicationKey) {
        // 创建认证信息
        $credentials = base64_encode($keyId . ':' . $applicationKey);
        try {
            $client = Client::get();
            $client->setHeader('Authorization', 'Basic ' . $credentials)
                    ->setMethod(Client::METHOD_GET)
                    ->send('https://api.backblazeb2.com/b2api/v2/b2_authorize_account');
            // 获取响应
            if ($client->getResponseStatus() === 200) return json_decode($client->getResponseBody(), true);
        } catch (Exception $e) {
            error_log($e->getMessage());
        }
        return false;
    }
  
    /**
    * 获取上传URL和授权令牌
    * 
    * @param array $auth 认证信息
    * @param string $bucketId 存储桶ID
    * @return array|bool
    */
    private static function getUploadUrl(array $auth, string $bucketId) {
        try {
            $client = Client::get();
            $client->setHeader('Authorization', $auth['authorizationToken'])
                    ->setHeader('Content-Type', 'application/json')
                    ->setData(json_encode(['bucketId' => $bucketId]))
                    ->setMethod(Client::METHOD_POST)
                    ->send($auth['apiUrl'] . '/b2api/v2/b2_get_upload_url');
            // 处理返回值
            if ($client->getResponseStatus() === 200) return json_decode($client->getResponseBody(), true);
        } catch (Exception $e) {
            return false;
        }
        return false;
    }
  
    /**
    * 获取文件ID
    * 
    * @param array $auth 认证信息
    * @param string $bucketName 存储桶名称
    * @param string $fileName 文件名
    * @return string|bool
    */
    private static function getFileId(array $auth, string $bucketName, string $fileName) {
        try {
            $client = Client::get();
            $client->setHeader('Authorization', $auth['authorizationToken'])
                   ->setHeader('Content-Type', 'application/json')
                   ->setData(json_encode(['bucketName' => $bucketName, 'prefix' => $fileName, 'maxFileCount' => 1 ]))
                   ->setMethod(Client::METHOD_POST)
                   ->send($auth['apiUrl'] . '/b2api/v2/b2_list_file_names');
            if ($client->getResponseStatus() === 200) {
                $data = json_decode($client->getResponseBody(), true);
                if (!empty($data['files']) && $data['files'][0]['fileName'] === $fileName) return $data['files'][0]['fileId'];
            }
        } catch (Exception $e) {
            error_log($e->getMessage());
        }
        return false;
    }
}