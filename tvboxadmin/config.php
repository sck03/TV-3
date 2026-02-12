<?php
// 启用错误报告
// error_reporting(E_ALL);
// ini_set('display_errors', 1);
// ini_set('display_startup_errors', 1);

// 检查exec函数是否可用
if (!function_exists('exec')) {
    die('错误：exec() 函数被禁用，请在php.ini中启用或联系服务器管理员');
}

// 配置文件
define('JSON_FILE_PATH', '../tv.json');
define('GIT_AUTO_COMMIT', true);
define('GIT_REPO_PATH', '../');
define('PAUSE_FILE_PATH', '../pause.json');

// 测试Git命令是否可用
exec('git --version 2>&1', $gitOutput, $gitCode);
if ($gitCode !== 0) {
    die('错误：Git命令不可用，请确保Git已安装并配置正确路径');
}

// 初始化JSON文件
if (!file_exists(JSON_FILE_PATH)) {
    file_put_contents(JSON_FILE_PATH, json_encode(['urls' => []], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}
// 初始化pause.json文件
if (!file_exists(PAUSE_FILE_PATH)) {
    file_put_contents(PAUSE_FILE_PATH, json_encode(['paused_urls' => []], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}
// 安全版本的Git自动提交函数
function gitAutoCommit($operation, $dataName = '') {
    if (!GIT_AUTO_COMMIT) {
        return ['success' => true, 'no_changes' => true];
    }

    // 验证路径
    if (!file_exists(GIT_REPO_PATH) || !is_dir(GIT_REPO_PATH)) {
        return ['success' => false, 'error' => 'Git仓库路径不存在: ' . GIT_REPO_PATH];
    }

    if (!file_exists(GIT_REPO_PATH . '/.git')) {
        return ['success' => false, 'error' => '不是Git仓库: ' . GIT_REPO_PATH];
    }

    try {
        $repoPath = realpath(GIT_REPO_PATH);
        $currentDir = getcwd();

        if (!chdir($repoPath)) {
            return ['success' => false, 'error' => '无法切换到仓库目录'];
        }

        // 检查文件状态
        exec('git status --porcelain 2>&1', $statusOutput, $statusCode);

        // 添加文件
        exec('git add tv.json 2>&1', $addOutput, $addCode);
        if ($addCode !== 0) {
            chdir($currentDir);
            return ['success' => false, 'error' => 'Git添加失败: ' . implode(' ', $addOutput)];
        }

        // 提交
        $commitMessage = '多仓源' . $operation;
        if (!empty($dataName)) {
            $commitMessage .= ': ' . $dataName;
        }
        $commitMessage .= ' - ' . date('Y-m-d H:i:s');

        exec('git commit -m "' . $commitMessage . '" 2>&1', $commitOutput, $commitCode);

        $commitStr = implode(' ', $commitOutput);
        if ($commitCode !== 0) {
            // 检查是否无更改
            if (strpos($commitStr, 'nothing to commit') !== false) {
                chdir($currentDir);
                return ['success' => true, 'no_changes' => true];
            }
            chdir($currentDir);
            return ['success' => false, 'error' => 'Git提交失败: ' . $commitStr];
        }

        // 推送（先尝试正常推送，失败则强制推送）
        exec('git push origin main 2>&1', $pushOutput, $pushCode);
        if ($pushCode !== 0) {
            // 尝试强制推送
            exec('git push --force origin main 2>&1', $forceOutput, $forceCode);
            if ($forceCode !== 0) {
                chdir($currentDir);
                return ['success' => false, 'error' => 'Git推送失败: ' . implode(' ', $forceOutput)];
            }
        }

        chdir($currentDir);
        return ['success' => true, 'commit_message' => $commitMessage];

    } catch (Exception $e) {
        // 确保切换回原目录
        if (isset($currentDir)) {
            @chdir($currentDir);
        }
        return ['success' => false, 'error' => '异常: ' . $e->getMessage()];
    }
}

// 增加暂停和启用函数
function pauseTvItem($id) {
    $tvList = getTvData();
    $pausedItem = null;
    
    foreach ($tvList as $index => $item) {
        if ($item['id'] == $id && $item['status'] === 'active') {
            $pausedItem = $item;
            $pausedItem['status'] = 'paused';
            $pausedItem['original_id'] = $item['id']; // 保存原始ID
            $tvList[$index] = $pausedItem;
            break;
        }
    }
    
    if ($pausedItem) {
        return saveTvData($tvList, '暂停', $pausedItem['name']);
    }
    return false;
}

function resumeTvItem($id) {
    $tvList = getTvData();
    $resumedItem = null;
    $resumedIndex = -1;
    
    // 找到要启用的项目
    foreach ($tvList as $index => $item) {
        if ($item['id'] == $id && $item['status'] === 'paused') {
            $resumedItem = $item;
            $resumedIndex = $index;
            break;
        }
    }
    
    if ($resumedItem) {
        // 移除暂停项目
        array_splice($tvList, $resumedIndex, 1);
        
        // 重新插入到原始位置（如果可能）
        $originalPosition = $resumedItem['original_id'] - 1;
        $originalPosition = max(0, min($originalPosition, count($tvList)));
        
        // 插入到原始位置
        array_splice($tvList, $originalPosition, 0, [[
            'id' => $originalPosition + 1,
            'name' => $resumedItem['name'],
            'url' => $resumedItem['url'],
            'status' => 'active'
        ]]);
        
        // 重新编号所有活跃项目
        $activeCount = 0;
        foreach ($tvList as &$item) {
            if ($item['status'] === 'active') {
                $activeCount++;
                $item['id'] = $activeCount;
            }
        }
        
        return saveTvData($tvList, '启用', $resumedItem['name']);
    }
    return false;
}

// 读取数据函数
function getTvData() {
    $json = file_get_contents(JSON_FILE_PATH);
    $data = json_decode($json, true) ?: ['urls' => []];
    $urls = $data['urls'] ?: [];

    // 读取暂停数据
    $pauseJson = file_get_contents(PAUSE_FILE_PATH);
    $pauseData = json_decode($pauseJson, true) ?: ['paused_urls' => []];
    $pausedUrls = $pauseData['paused_urls'] ?: [];

    $tvList = [];
    
    // 合并活跃数据
    foreach ($urls as $index => $item) {
        $tvList[] = [
            'id' => $index + 1,
            'name' => $item['name'],
            'url' => $item['url'],
            'status' => 'active' // 活跃状态
        ];
    }
    
    // 合并暂停数据（ID从活跃数据最大ID+1开始）
    $maxActiveId = count($tvList);
    foreach ($pausedUrls as $index => $item) {
        $tvList[] = [
            'id' => $maxActiveId + $index + 1,
            'name' => $item['name'],
            'url' => $item['url'],
            'status' => 'paused', // 暂停状态
            'original_id' => $item['original_id'] // 保存原始ID
        ];
    }
    
    return $tvList;
}

// 保存数据函数
function saveTvData($tvList, $operation = '更新', $dataName = '') {
    // 只保存活跃状态的数据到tv.json
    $activeUrls = [];
    foreach ($tvList as $item) {
        if ($item['status'] === 'active') {
            $activeUrls[] = [
                'url' => $item['url'],
                'name' => $item['name']
            ];
        }
    }

    $finalData = ['urls' => $activeUrls];
    $json = json_encode($finalData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    $saveResult = file_put_contents(JSON_FILE_PATH, $json);
    
    // 保存暂停数据到pause.json
    $pausedUrls = [];
    foreach ($tvList as $item) {
        if ($item['status'] === 'paused') {
            $pausedUrls[] = [
                'url' => $item['url'],
                'name' => $item['name'],
                'original_id' => $item['original_id'] ?? $item['id'] // 保存原始ID
            ];
        }
    }
    
    $pauseData = ['paused_urls' => $pausedUrls];
    $pauseJson = json_encode($pauseData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    file_put_contents(PAUSE_FILE_PATH, $pauseJson);

    if ($saveResult !== false && GIT_AUTO_COMMIT) {
        $gitResult = gitAutoCommit($operation, $dataName);
        return [
            'file_save' => $saveResult !== false,
            'git_push' => $gitResult['success'] ?? false,
            'no_changes' => $gitResult['no_changes'] ?? false,
            'commit_message' => $gitResult['commit_message'] ?? '',
            'error' => $gitResult['error'] ?? ''
        ];
    }

    return [
        'file_save' => $saveResult !== false,
        'git_push' => false,
        'no_changes' => false,
        'commit_message' => '',
        'error' => ''
    ];
}

// 移动排序函数
function reorderTvData($id, $direction) {
    $tvList = getTvData();
    $index = -1;

    foreach ($tvList as $i => $item) {
        if ($item['id'] == $id) {
            $index = $i;
            break;
        }
    }

    if ($index == -1) return false;

    if ($direction == 'up' && $index > 0) {
        $temp = $tvList[$index - 1];
        $tvList[$index - 1] = $tvList[$index];
        $tvList[$index] = $temp;
    } elseif ($direction == 'down' && $index < count($tvList) - 1) {
        $temp = $tvList[$index + 1];
        $tvList[$index + 1] = $tvList[$index];
        $tvList[$index] = $temp;
    } else {
        return false;
    }

    $fixedList = [];
    foreach ($tvList as $i => $item) {
        $fixedList[] = [
            'id' => $i + 1,
            'name' => $item['name'],
            'url' => $item['url'],
            'status' => $item['status'] // 保持状态
        ];
    }

    return saveTvData($fixedList, $direction == 'up' ? '上移' : '下移');
}

// 拖拽排序函数
function dragReorderTvData($fromId, $toId) {
    $tvList = getTvData();
    $fromIndex = -1;
    $toIndex = -1;

    foreach ($tvList as $i => $item) {
        if ($item['id'] == $fromId) $fromIndex = $i;
        if ($item['id'] == $toId) $toIndex = $i;
        if ($fromIndex != -1 && $toIndex != -1) break;
    }

    if ($fromIndex == -1 || $toIndex == -1 || $fromIndex == $toIndex) {
        return false;
    }

    $movedItem = $tvList[$fromIndex];
    array_splice($tvList, $fromIndex, 1);
    array_splice($tvList, $toIndex, 0, [$movedItem]);

    $fixedList = [];
    foreach ($tvList as $i => $item) {
        $fixedList[] = [
            'id' => $i + 1,
            'name' => $item['name'],
            'url' => $item['url'],
            'status' => $item['status'] // 保持状态
        ];
    }

    return saveTvData($fixedList, '拖拽排序');
}
?>