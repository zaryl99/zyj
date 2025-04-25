<?php
// 开启错误报告（上线时关闭）
error_reporting(E_ALL);
ini_set('display_errors', 1);

// 配置常量
define('CLONE_DIR', __DIR__.'/clone');
define('MAX_FILE_SIZE', 1024 * 1024 * 20); // 20MB限制
define('USER_AGENTS', [
    'vivo' => 'Mozilla/5.0 (Linux; Android 11; V2046A) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/96.0.4664.45 Mobile Safari/537.36',
    'iphone' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 15_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/15.0 Mobile/15E148 Safari/604.1',
    'huawei' => 'Mozilla/5.0 (Linux; Android 10; LYA-AL00) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/96.0.4664.45 Mobile Safari/537.36',
    'windows' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/96.0.4664.45 Safari/537.36',
    'mac' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/15.0 Safari/605.1.15'
]);

// 创建存储目录
if (!file_exists(CLONE_DIR)) {
    mkdir(CLONE_DIR, 0755, true);
}

// 处理不同操作
$action = $_POST['action'] ?? '';
switch ($action) {
    case 'start_clone':
        handleClone();
        break;
    case 'delete_task':
        deleteTask();
        break;
    case 'delete_all':
        deleteAllTasks();
        break;
    case 'get_status':
        getTaskStatus();
        break;
    default:
        displayUI();
}

function handleClone() {
    header('Content-Type: application/json');
    
    $url = filter_input(INPUT_POST, 'url', FILTER_VALIDATE_URL);
    if (!$url) {
        $raw_url = trim($_POST['url'] ?? '');
        if ($raw_url && !preg_match('#^https?://#i', $raw_url)) {
            $url = 'http://' . $raw_url;
            if (!filter_var($url, FILTER_VALIDATE_URL)) {
                echo json_encode(['error' => '无效的URL'], JSON_UNESCAPED_UNICODE);
                exit;
            }
        } else {
            echo json_encode(['error' => '无效的URL'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    $taskId = uniqid('task_');
    $taskDir = CLONE_DIR.DIRECTORY_SEPARATOR.$taskId;
    $zipFile = CLONE_DIR.DIRECTORY_SEPARATOR."$taskId.zip";
    
    $taskData = [
        'id' => $taskId,
        'url' => $url,
        'status' => '克隆中',
        'start_time' => microtime(true),
        'end_time' => null,
        'files' => [],
        'resource_types' => ['html' => 0, 'css' => 0, 'js' => 0, 'images' => 0, 'fonts' => 0, 'videos' => 0, 'audio' => 0],
        'zip_size' => 0,
        'error' => '',
        'failed_resources' => [],
        'progress' => 0,
        'message' => '开始克隆网站...',
        'speed' => 0,
        'title' => '',
        'response_time' => 0
    ];

    try {
        mkdir($taskDir, 0755);
        $startTime = microtime(true);
        $html = fetchUrlContent($url);
        $responseTime = microtime(true) - $startTime;
        
        $doc = new DOMDocument();
        @$doc->loadHTML($html);
        $titles = $doc->getElementsByTagName('title');
        $taskData['title'] = $titles->length > 0 ? $titles->item(0)->nodeValue : '未知标题';
        
        file_put_contents("$taskDir/index.html", $html);
        $taskData['files'][] = 'index.html';
        $taskData['resource_types']['html']++;
        $taskData['progress'] = 20;
        $taskData['message'] = '主页面下载完成，开始获取资源...';
        $taskData['response_time'] = $responseTime;

        $baseUrl = parse_url($url, PHP_URL_SCHEME).'://'.parse_url($url, PHP_URL_HOST);
        downloadAllResources($html, $baseUrl, $taskDir, $taskData);
        
        foreach ($taskData['files'] as $file) {
            if (pathinfo($file, PATHINFO_EXTENSION) === 'css') {
                $cssPath = $taskDir.DIRECTORY_SEPARATOR.$file;
                $cssContent = file_get_contents($cssPath);
                $cssContent = processCssUrls($cssContent, $baseUrl, $taskDir, $taskData);
                file_put_contents($cssPath, $cssContent);
            }
        }

        $html = updateHtmlPaths($html, $taskData['files'], $baseUrl);
        file_put_contents("$taskDir/index.html", $html);

        createZipArchive($taskDir, $zipFile, $taskData);
        $taskData['status'] = '成功';
        $taskData['zip_size'] = filesize($zipFile);
        $taskData['speed'] = $taskData['zip_size'] / ((microtime(true) - $taskData['start_time']) * 1024);
        $taskData['progress'] = 100;
        $taskData['message'] = '克隆完成！可下载或预览网站';

    } catch (Exception $e) {
        $taskData['status'] = '失败';
        $taskData['error'] = $e->getMessage();
        $taskData['message'] = '克隆失败：'.$e->getMessage();
    }

    $taskData['end_time'] = microtime(true);
    file_put_contents("$taskDir/task.json", json_encode($taskData, JSON_UNESCAPED_UNICODE));
    
    echo json_encode(['taskId' => $taskId, 'success' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

function fetchUrlContent($url) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_MAXFILESIZE, MAX_FILE_SIZE);
    curl_setopt($ch, CURLOPT_USERAGENT, getRandomUserAgent());
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $content = curl_exec($ch);
    
    if (curl_errno($ch)) {
        throw new Exception('下载失败: '.curl_error($ch));
    }
    if (curl_getinfo($ch, CURLINFO_HTTP_CODE) !== 200) {
        throw new Exception('HTTP错误: '.curl_getinfo($ch, CURLINFO_HTTP_CODE));
    }
    
    curl_close($ch);
    return $content;
}

function getRandomUserAgent() {
    return USER_AGENTS[array_rand(USER_AGENTS)];
}

function downloadAllResources($html, $baseUrl, $taskDir, &$taskData) {
    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    $totalResources = 0;
    $downloaded = 0;

    $totalResources += $dom->getElementsByTagName('link')->length;
    $totalResources += $dom->getElementsByTagName('img')->length;
    $totalResources += $dom->getElementsByTagName('script')->length;
    $totalResources += $dom->getElementsByTagName('video')->length;
    $totalResources += $dom->getElementsByTagName('audio')->length;

    foreach ($dom->getElementsByTagName('link') as $link) {
        if ($link->getAttribute('rel') === 'stylesheet') {
            $href = resolveUrl($baseUrl, $link->getAttribute('href'));
            downloadAsset($href, $taskDir, $taskData, 'css');
            $downloaded++;
            updateProgress($taskData, $downloaded, $totalResources);
        }
    }
    
    foreach ($dom->getElementsByTagName('img') as $img) {
        $src = resolveUrl($baseUrl, $img->getAttribute('src'));
        downloadAsset($src, $taskDir, $taskData, 'images');
        $downloaded++;
        updateProgress($taskData, $downloaded, $totalResources);
    }
    
    foreach ($dom->getElementsByTagName('script') as $script) {
        if ($script->hasAttribute('src')) {
            $src = resolveUrl($baseUrl, $script->getAttribute('src'));
            downloadAsset($src, $taskDir, $taskData, 'js');
            $downloaded++;
            updateProgress($taskData, $downloaded, $totalResources);
        }
    }

    foreach ($dom->getElementsByTagName('video') as $video) {
        if ($video->hasAttribute('src')) {
            $src = resolveUrl($baseUrl, $video->getAttribute('src'));
            downloadAsset($src, $taskDir, $taskData, 'videos');
            $downloaded++;
            updateProgress($taskData, $downloaded, $totalResources);
        }
    }

    foreach ($dom->getElementsByTagName('audio') as $audio) {
        if ($audio->hasAttribute('src')) {
            $src = resolveUrl($baseUrl, $audio->getAttribute('src'));
            downloadAsset($src, $taskDir, $taskData, 'audio');
            $downloaded++;
            updateProgress($taskData, $downloaded, $totalResources);
        }
    }
}

function processCssUrls($cssContent, $baseUrl, $taskDir, &$taskData) {
    preg_match_all('/url\(["\']?(.*?)["\']?\)/i', $cssContent, $matches);
    foreach ($matches[1] as $url) {
        if (strpos($url, 'data:') === 0) continue;
        $fullUrl = resolveUrl($baseUrl, $url);
        $ext = strtolower(pathinfo(parse_url($fullUrl, PHP_URL_PATH), PATHINFO_EXTENSION));
        $type = in_array($ext, ['ttf', 'woff', 'woff2']) ? 'fonts' : 'images';
        $localPath = downloadAsset($fullUrl, $taskDir, $taskData, $type);
        if ($localPath) {
            $relativePath = str_replace($taskDir.DIRECTORY_SEPARATOR, '', $localPath);
            $cssContent = str_replace($url, $relativePath, $cssContent);
        }
    }
    return $cssContent;
}

function updateProgress(&$taskData, $downloaded, $total) {
    $taskData['progress'] = min(90, 20 + ($downloaded / $total) * 70);
    $taskData['message'] = "正在下载资源：$downloaded/$total";
    file_put_contents(CLONE_DIR.DIRECTORY_SEPARATOR.$taskData['id'].DIRECTORY_SEPARATOR.'task.json', json_encode($taskData, JSON_UNESCAPED_UNICODE));
}

function updateHtmlPaths($html, $files, $baseUrl) {
    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    
    foreach ($dom->getElementsByTagName('link') as $link) {
        if ($link->hasAttribute('href')) {
            $href = $link->getAttribute('href');
            if (strpos($href, $baseUrl) === 0) {
                $relPath = substr($href, strlen($baseUrl));
                $link->setAttribute('href', ltrim($relPath, '/'));
            }
        }
    }
    
    foreach (['img', 'script', 'video', 'audio'] as $tag) {
        foreach ($dom->getElementsByTagName($tag) as $element) {
            if ($element->hasAttribute('src')) {
                $src = $element->getAttribute('src');
                if (strpos($src, $baseUrl) === 0) {
                    $relPath = substr($src, strlen($baseUrl));
                    $element->setAttribute('src', ltrim($relPath, '/'));
                }
            }
        }
    }
    
    return $dom->saveHTML();
}

function resolveUrl($base, $url) {
    if (parse_url($url, PHP_URL_SCHEME) !== null) {
        return $url;
    }
    return rtrim($base, '/').'/'.ltrim($url, '/');
}

function downloadAsset($url, $taskDir, &$taskData, $type) {
    try {
        $path = parse_url($url, PHP_URL_PATH);
        $localPath = $taskDir.$path;
        
        if (!file_exists(dirname($localPath))) {
            mkdir(dirname($localPath), 0755, true);
        }
        
        $content = file_get_contents($url);
        if ($content === false) {
            $error = error_get_last();
            throw new Exception("无法下载资源: $url, 原因: " . ($error['message'] ?? '未知错误'));
        }
        
        file_put_contents($localPath, $content);
        $taskData['files'][] = $path;
        $taskData['resource_types'][$type]++;
        return $localPath;
    } catch (Exception $e) {
        $taskData['failed_resources'][] = ['url' => $url, 'error' => $e->getMessage()];
        $taskData['error'] .= $e->getMessage() . "; ";
        return false;
    }
}

function createZipArchive($source, $destination, &$taskData) {
    $zip = new ZipArchive();
    if ($zip->open($destination, ZipArchive::CREATE) !== true) {
        throw new Exception("无法创建ZIP文件");
    }
    
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source),
        RecursiveIteratorIterator::LEAVES_ONLY
    );

    foreach ($files as $file) {
        if (!$file->isDir()) {
            $filePath = $file->getRealPath();
            $relativePath = substr($filePath, strlen($source) + 1);
            $zip->addFile($filePath, $relativePath);
        }
    }
    
    $zip->close();
}

function deleteTask() {
    header('Content-Type: application/json');
    $taskId = $_POST['taskId'] ?? '';
    
    if (!preg_match('/^task_[\w-]+$/', $taskId)) {
        echo json_encode(['error' => '无效的任务ID'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    $dir = CLONE_DIR.DIRECTORY_SEPARATOR.$taskId;
    if (file_exists($dir)) {
        array_map('unlink', glob("$dir/*.*"));
        rmdir($dir);
    }
    
    $zip = CLONE_DIR.DIRECTORY_SEPARATOR."$taskId.zip";
    if (file_exists($zip)) {
        unlink($zip);
    }
    
    echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

function deleteAllTasks() {
    header('Content-Type: application/json');
    foreach (glob(CLONE_DIR.DIRECTORY_SEPARATOR.'task_*') as $dir) {
        if (is_dir($dir)) {
            array_map('unlink', glob("$dir/*.*"));
            rmdir($dir);
        }
    }
    foreach (glob(CLONE_DIR.DIRECTORY_SEPARATOR.'*.zip') as $zip) {
        unlink($zip);
    }
    echo json_encode(['success' => true, 'message' => '已清除所有任务'], JSON_UNESCAPED_UNICODE);
    exit;
}

function getTaskStatus() {
    header('Content-Type: application/json');
    $tasks = [];
    
    foreach (glob(CLONE_DIR.DIRECTORY_SEPARATOR.'task_*') as $dir) {
        if (is_dir($dir)) {
            $taskFile = "$dir/task.json";
            if (file_exists($taskFile)) {
                $taskData = json_decode(file_get_contents($taskFile), true);
                $taskData['time_used'] = round(($taskData['end_time'] ?? microtime(true)) - $taskData['start_time'], 3);
                $tasks[] = $taskData;
            }
        }
    }
    
    echo json_encode($tasks, JSON_UNESCAPED_UNICODE);
    exit;
}

function displayUI() {
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>🐧 网站克隆小助手</title>
    <style>
        body {
            font-family: '微软雅黑', sans-serif;
            margin: 0;
            padding: 20px;
            background: #f0f2f5;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.1);
        }
        .status-boxes {
            display: flex;
            justify-content: space-between;
            flex-direction: row;
            margin-bottom: 15px;
            gap: 3px;
        }
        .status-box {
            flex: 0;
            padding: 15px 20px 0px 25px;
            border-radius: 10px;
            text-align: center;
            color: white;
            font-weight: bold;
            width: 120px;
            height: 60px;
            font-size: 20px;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }
        .status-box > div {
            margin-top: -5px;
        }
        .status-success { background: #4CAF50; }
        .status-fail { background: #dc3545; }
        .status-progress { background: #17a2b8; }
        .status-count {
            font-size: 24px;
            margin: 5px 0;
        }
        .input-group {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }
        #urlInput {
            flex: 1;
            padding: 12px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 16px;
        }
        button {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: bold;
            transition: 0.3s;
        }
        #cloneBtn {
            background: #4CAF50;
            color: white;
        }
        #cloneBtn:hover {
            background: #45a049;
        }
        #refreshBtn {
            background: #17a2b8;
            color: white;
        }
        #refreshBtn:hover {
            background: #138496;
        }
        #deleteAllBtn {
            background: #dc3545;
            color: white;
        }
        #deleteAllBtn:hover {
            background: #c82333;
        }
        .task-card {
            background: #fff;
            border: 1px solid #e0e0e0;
            border-radius: 10px;
            padding: 20px;
            margin: 15px 0;
            position: relative;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            transition: transform 0.2s;
        }
        .task-card:hover {
            transform: translateY(-2px);
        }
        .status {
            padding: 5px 10px;
            border-radius: 5px;
            font-weight: bold;
        }
        .status-克隆中 { background: #fff3cd; color: #856404; }
        .status-成功 { background: #d4edda; color: #155724; }
        .status-失败 { background: #f8d7da; color: #721c24; }
        .delete-btn {
            position: absolute;
            top: 20px;
            right: 5px;
            background: #dc3545;
            color: white;
            width: 80px;
        }
        .actions {
            display: flex;
            justify-content: space-between;
            margin-top: 10px;
        }
        .download-btn, .preview-btn {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: bold;
            transition: 0.3s;
            width: 100px;
            text-align: center;
        }
        .stats {
            display: block;
            margin: 15px 0;
            background: #fafafa;
            padding: 15px;
            border-radius: 8px;
        }
        .stats div {
            padding: 5px;
            border-radius: 5px;
            background: #fff;
            margin-bottom: 5px;
        }
        .progress-bar {
            width: 100%;
            background: #e0e0e0;
            height: 20px;
            border-radius: 10px;
            overflow: hidden;
            margin: 10px 0;
        }
        .progress {
            height: 100%;
            background: linear-gradient(90deg, #4CAF50, #66bb6a);
            transition: width 0.3s;
        }
        .emoji { margin-right: 8px; }
        .failed-details, .more-info {
            margin-top: 10px;
            display: none;
        }
        .failed-toggle, .toggle-more {
            color: #007bff;
            cursor: pointer;
            margin-top: 5px;
        }
        @media (max-width: 600px) {
            .status-boxes {
                flex-direction: row;
                flex-wrap: nowrap;
            }
            .status-box {
                width: 100px;
                height: 50px;
                padding: 26px;
                font-size: 23px;
            }
            .status-count {
                font-size: 20px;
            }
            .input-group {
                flex-direction: column;
            }
            button {
                width: %;
            }
            .actions {
                flex-direction: ;
            }
            .download-btn, .preview-btn {
                width: %;
                margin: 5px 0;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🐧 网站克隆小助手</h1>
        <div class="status-boxes">
            <div class="status-box status-success">
                <div>✅</div>
                <div class="status-count" id="successCount">0</div>
            </div>
            <div class="status-box status-fail">
                <div>❌</div>
                <div class="status-count" id="failCount">0</div>
            </div>
            <div class="status-box status-progress">
                <div>🔄</div>
                <div class="status-count" id="progressCount">0</div>
            </div>
        </div>
        <div class="input-group">
            <input type="url" id="urlInput" placeholder="请输入要克隆的网站URL...">
            <button id="cloneBtn" onclick="startClone()">🚀 开始克隆</button>
            <button id="refreshBtn" onclick="refreshTasks()">🔄 刷新</button>
            <button id="deleteAllBtn" onclick="deleteAllTasks()">🗑️ 清空所有</button>
        </div>
        <div id="taskList"></div>
    </div>

    <script>
        function startClone() {
            let url = document.getElementById('urlInput').value.trim();
            if (!url) {
                alert('请输入有效的URL');
                return;
            }
            
            const urlPattern = /^(https?:\/\/)?([\w-]+\.)+[\w-]+(\/[\w- .\/?%&=]*)?$/i;
            if (!urlPattern.test(url)) {
                alert('URL 格式错误，请输入正确的网站地址');
                return;
            }
            
            if (!url.match(/^https?:\/\//i)) {
                url = 'http://' + url;
                document.getElementById('urlInput').value = url;
            }

            document.getElementById('urlInput').value = '';
            const taskId = 'task_' + Date.now();
            addTaskCard(taskId, url);

            fetch('?', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    action: 'start_clone',
                    url: url
                })
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('网络请求失败');
                }
                return response.json();
            })
            .then(data => {
                if (data.error) {
                    updateTaskCard({ id: taskId, status: '失败', message: data.error });
                    return;
                }
                if (data.success && data.taskId) {
                    const oldCard = document.getElementById(taskId);
                    if (oldCard) {
                        oldCard.id = data.taskId;
                    }
                    monitorTask(data.taskId);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                updateTaskCard({ id: taskId, status: '失败', message: '网络错误，请检查连接' });
            });
        }

        function addTaskCard(taskId, url) {
            const card = document.createElement('div');
            card.className = 'task-card';
            card.id = taskId;
            card.innerHTML = `
                <button class="delete-btn" onclick="deleteTask('${taskId}')">🗑️ 删除</button>
                <h3><span class="emoji">🔗</span></h3>
                <h3>${url}</h3>
                <div class="stats">
                    <div>📌 状态: <span class="status status-克隆中">🔄 克隆中...</span></div>
                    <div>🏷️ 标题: <span class="title">-</span></div>
                    <div>⏱️ 用时: <span class="time">0秒</span></div>
                    <div class="more-info" style="display: none;">
                        <div>📂 文件数: <span class="count">0</span></div>
                        <div>📦 ZIP大小: <span class="size">0MB</span></div>
                        <div>⚡ 速度: <span class="speed">0 KB/s</span></div>
                        <div>🕒 开始时间: <span class="start">${new Date().toLocaleString()}</span></div>
                        <div>🕒 完成时间: <span class="finish">-</span></div>
                        <div>📶 响应: <span class="response">0ms</span></div>
                        <div>📊 资源类型: <span class="resources">-</span></div>
                        <div>📋 任务ID: <span class="task-id">${taskId}</span></div>
                    </div>
                    <div class="toggle-more" onclick="toggleMoreInfo(this, '${taskId}')">
                        <span class="toggle-text">查看更多信息(8)</span>
                    </div>
                </div>
                <div class="progress-bar"><div class="progress" style="width: 0%"></div></div>
                <div><span class="emoji">📢</span>消息: <span class="message">开始克隆网站...</span></div>
                <div class="actions">
                    <button class="download-btn" disabled>⬇️ 下载</button>
                    <button class="preview-btn" disabled>👀 预览</button>
                </div>
            `;
            document.getElementById('taskList').prepend(card);
        }

        function monitorTask(taskId) {
            const interval = setInterval(() => {
                fetch('?', {
                    method: 'POST',
                    body: new URLSearchParams({ action: 'get_status' })
                })
                .then(res => res.json())
                .then(tasks => {
                    const task = tasks.find(t => t.id === taskId);
                    if (!task) return;
                    updateTaskCard(task);
                    if (task.status !== '克隆中') clearInterval(interval);
                    updateStatusCounts(tasks);
                });
            }, 1000);
        }

        function updateTaskCard(task) {
            const card = document.getElementById(task.id);
            if (!card) return;

            const status = card.querySelector('.status');
            let statusContent = getStatusIcon(task.status) + task.status;
            
            if (task.failed_resources && task.failed_resources.length > 0) {
                const failedCount = task.failed_resources.length;
                const visibleResources = task.failed_resources.slice(0, 3);
                let failedHtml = ': ' + visibleResources.map(resource => `无法下载资源: ${resource.url} (${resource.error})`).join('; ');
                
                if (failedCount > 3) {
                    const hiddenCount = failedCount - 3;
                    failedHtml += `<br><span class="failed-toggle" data-task-id="${task.id}" data-hidden-count="${hiddenCount}">查看更多(${hiddenCount})</span>
                        <div class="failed-details" id="failed-details-${task.id}">
                            ${task.failed_resources.slice(3).map(resource => `无法下载资源: ${resource.url} (${resource.error})`).join(';<br>')}
                        </div>`;
                }
                statusContent += failedHtml;
            }
            
            status.className = `status status-${task.status}`;
            status.innerHTML = statusContent;

            card.querySelector('.title').textContent = task.title || '-';
            card.querySelector('.time').textContent = `${task.time_used}秒`;
            card.querySelector('.count').textContent = task.files?.length || 0;
            card.querySelector('.size').textContent = formatSize(task.zip_size);
            card.querySelector('.speed').textContent = `${task.speed.toFixed(2)} KB/s`;
            card.querySelector('.start').textContent = new Date(task.start_time * 1000).toLocaleString();
            card.querySelector('.finish').textContent = task.end_time 
                ? new Date(task.end_time * 1000).toLocaleString() 
                : '-';
            card.querySelector('.response').textContent = `${(task.response_time * 1000).toFixed(0)}ms`;
            card.querySelector('.resources').textContent = `HTML: ${task.resource_types.html}, CSS: ${task.resource_types.css}, JS: ${task.resource_types.js}, 图片: ${task.resource_types.images}, 字体: ${task.resource_types.fonts}, 视频: ${task.resource_types.videos}, 音频: ${task.resource_types.audio}`;
            card.querySelector('.task-id').textContent = task.id;
            card.querySelector('.progress').style.width = `${task.progress}%`;
            card.querySelector('.message').textContent = task.message;

            if (task.status === '成功') {
                const downloadBtn = card.querySelector('.download-btn');
                downloadBtn.disabled = false;
                downloadBtn.onclick = () => window.location.href = `clone/${task.id}.zip`;
                
                const previewBtn = card.querySelector('.preview-btn');
                previewBtn.disabled = false;
                previewBtn.onclick = () => window.open(`clone/${task.id}/index.html`, '_blank');
            }
        }

        function updateStatusCounts(tasks) {
            const successCount = tasks.filter(t => t.status === '成功').length;
            const failCount = tasks.filter(t => t.status === '失败').length;
            const progressCount = tasks.filter(t => t.status === '克隆中').length;

            document.getElementById('successCount').textContent = successCount;
            document.getElementById('failCount').textContent = failCount;
            document.getElementById('progressCount').textContent = progressCount;
        }

        function toggleFailedDetails(element, taskId, hiddenCount) {
            const details = document.getElementById(`failed-details-${taskId}`);
            if (!details) return;

            if (details.style.display === 'block') {
                details.style.display = 'none';
                element.textContent = `查看更多(${hiddenCount})`;
            } else {
                details.style.display = 'block';
                element.textContent = '收起';
            }
        }

        function toggleMoreInfo(element, taskId) {
            const moreInfo = document.querySelector(`#${taskId} .more-info`);
            const toggleText = element.querySelector('.toggle-text');
            
            if (moreInfo.style.display === 'none' || !moreInfo.style.display) {
                moreInfo.style.display = 'block';
                toggleText.textContent = '收起';
            } else {
                moreInfo.style.display = 'none';
                toggleText.textContent = '查看更多(8)';
            }
        }

        function deleteTask(taskId) {
            if (!confirm('确定要删除此任务吗？')) return;

            fetch('?', {
                method: 'POST',
                body: new URLSearchParams({
                    action: 'delete_task',
                    taskId: taskId
                })
            })
            .then(res => res.json())
            .then(() => {
                document.getElementById(taskId)?.remove();
                refreshTasks();
            })
            .catch(error => {
                console.error('删除失败:', error);
            });
        }

        function deleteAllTasks() {
            if (!confirm('确定要清空所有任务吗？此操作不可恢复！')) return;

            fetch('?', {
                method: 'POST',
                body: new URLSearchParams({
                    action: 'delete_all'
                })
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    alert(data.message);
                    document.getElementById('taskList').innerHTML = '';
                    updateStatusCounts([]);
                }
            });
        }

        function refreshTasks() {
            fetch('?', {
                method: 'POST',
                body: new URLSearchParams({ action: 'get_status' })
            })
            .then(res => res.json())
            .then(tasks => {
                document.getElementById('taskList').innerHTML = '';
                tasks.forEach(task => {
                    addTaskCard(task.id, task.url);
                    updateTaskCard(task);
                });
                updateStatusCounts(tasks);
            });
        }

        function getStatusIcon(status) {
            return {
                '克隆中': '🔄',
                '成功': '✅',
                '失败': '❌'
            }[status] || '';
        }

        function formatSize(bytes) {
            if (!bytes) return '0B';
            const units = ['B', 'KB', 'MB', 'GB'];
            let i = 0;
            while (bytes >= 1024 && i < units.length-1) {
                bytes /= 1024;
                i++;
            }
            return bytes.toFixed(2) + units[i];
        }

        window.onload = () => {
            refreshTasks();
        };

        document.getElementById('taskList').addEventListener('click', function(e) {
            if (e.target.classList.contains('failed-toggle')) {
                const taskId = e.target.getAttribute('data-task-id');
                const hiddenCount = e.target.getAttribute('data-hidden-count');
                toggleFailedDetails(e.target, taskId, hiddenCount);
            }
        });
    </script>
</body>
</html>
<?php
}
?>