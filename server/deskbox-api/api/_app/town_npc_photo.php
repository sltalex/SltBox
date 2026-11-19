<?php
declare(strict_types=1);

if (!defined('DESKBOX_API_ENTRY')) { http_response_code(404); exit; }
require_once __DIR__ . '/town_agent.php';

const TOWN_NPC_PHOTO_MAX_BYTES = 4194304;
const TOWN_NPC_PHOTO_MAX_PIXELS = 4000000;

/** Validate the request-owned temporary file without copying or persisting it. */
function town_npc_photo_file(array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null) || !is_uploaded_file((string)$file['tmp_name'])) {
        throw new ApiError('invalid_photo', '图片上传失败。', 400);
    }
    $path = (string)$file['tmp_name'];
    $size = (int)($file['size'] ?? 0);
    if ($size < 1 || $size > TOWN_NPC_PHOTO_MAX_BYTES) throw new ApiError('invalid_photo', '图片大小必须在 1 B～4 MB 之间。', 413);
    $info = @getimagesize($path);
    if (!is_array($info)) throw new ApiError('invalid_photo', '图片格式无法识别。', 415);
    $mime = strtolower((string)($info['mime'] ?? ''));
    if (!in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true)) throw new ApiError('invalid_photo', '只支持 PNG、JPEG 或 WebP 图片。', 415);
    $width = (int)($info[0] ?? 0); $height = (int)($info[1] ?? 0);
    if ($width < 1 || $height < 1 || $width * $height > TOWN_NPC_PHOTO_MAX_PIXELS) throw new ApiError('invalid_photo', '图片像素超过 400 万限制。', 413);
    return ['path' => $path, 'mime' => $mime, 'width' => $width, 'height' => $height, 'size' => $size];
}

function town_npc_photo_worker_url(): string
{
    $configured = getenv('DESKBOX_NPC_WORKER_PHOTO_URL');
    if (is_string($configured) && trim($configured) !== '') return rtrim(trim($configured), '/') . '/internal/photo-facts';
    $path = dirname(__DIR__) . '/_config.local.php';
    if (is_file($path)) {
        $config = require $path;
        if (is_array($config)) {
            foreach (['npc_worker_photo_url', 'npc_worker_internal_url'] as $key) {
                if (is_string($config[$key] ?? null) && trim((string)$config[$key]) !== '') return rtrim(trim((string)$config[$key]), '/') . '/internal/photo-facts';
            }
        }
    }
    return 'http://127.0.0.1:28080/internal/photo-facts';
}

/** Send the image only to the internal Worker; return text facts, never raw bytes. */
function town_npc_photo_extract(array $file): array
{
    if (!function_exists('curl_init') || !class_exists('CURLFile')) throw new ApiError('photo_unavailable', '当前服务器没有可用的图片处理服务。', 503);
    $token = town_agent_service_token();
    if ($token === '') throw new ApiError('photo_unavailable', '图片处理服务未配置。', 503);
    $handle = curl_init(town_npc_photo_worker_url());
    if ($handle === false) throw new ApiError('photo_unavailable', '图片处理服务暂时不可用。', 503);
    $post = [
        'image' => new CURLFile($file['path'], $file['mime'], 'town-photo'),
        'mime' => $file['mime'], 'width' => (string)$file['width'], 'height' => (string)$file['height'],
    ];
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $post,
        CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 70,
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'X-DeskBox-Agent-Token: ' . $token, 'X-DeskBox-Worker-Id: photo-web'],
        CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0,
    ]);
    $body = curl_exec($handle); $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE); $error = curl_error($handle); curl_close($handle);
    if ($body === false || $status < 200 || $status >= 300) {
        if ($error !== '') error_log('[DeskBoxTownPhoto] worker request failed: ' . substr($error, 0, 160));
        throw new ApiError('photo_conversion_failed', '图片理解失败，请稍后重试。', 502);
    }
    try { $result = json_decode((string)$body, true, 16, JSON_THROW_ON_ERROR); }
    catch (JsonException $error) { throw new ApiError('photo_conversion_failed', '图片处理服务返回了无效结果。', 502); }
    if (!is_array($result) || !($result['ok'] ?? false) || !is_string($result['extracted_text'] ?? null) || !is_string($result['fact_summary'] ?? null)) {
        $code = is_array($result) && is_string($result['code'] ?? null) ? (string)$result['code'] : 'photo_conversion_failed';
        if (!in_array($code, ['invalid_photo', 'photo_model_failed', 'photo_model_timeout', 'photo_model_invalid'], true)) $code = 'photo_conversion_failed';
        throw new ApiError($code, is_array($result) && is_string($result['message'] ?? null) ? (string)$result['message'] : '图片理解失败，请稍后重试。', 502);
    }
    $extracted = trim(mb_substr((string)$result['extracted_text'], 0, 240, 'UTF-8'));
    $summary = trim(mb_substr((string)$result['fact_summary'], 0, 240, 'UTF-8'));
    if ($extracted === '' || $summary === '') throw new ApiError('photo_conversion_failed', '图片没有生成可用事实。', 502);
    return ['mode' => substr((string)($result['mode'] ?? 'model'), 0, 32), 'extracted_text' => $extracted, 'fact_summary' => $summary, 'model_name' => substr((string)($result['model_name'] ?? ''), 0, 128)];
}
