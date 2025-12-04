<?php
// 配置文件
define('JSON_FILE_PATH', '../tv.json'); // tv.json的路径（相对于admin文件夹）

// 初始化JSON文件（如果不存在则创建目标格式）
if (!file_exists(JSON_FILE_PATH)) {
    file_put_contents(JSON_FILE_PATH, json_encode(['urls' => []], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

// 读取数据：从tv.json的urls中读取，同时为每条数据临时添加ID（用于管理）
function getTvData() {
    $json = file_get_contents(JSON_FILE_PATH);
    $data = json_decode($json, true) ?: ['urls' => []];
    $urls = $data['urls'] ?: [];
    
    // 为每条数据添加临时ID（索引+1，用于编辑/删除识别）
    $tvList = [];
    foreach ($urls as $index => $item) {
        $tvList[] = [
            'id' => $index + 1, // 临时ID（基于数组索引，唯一）
            'name' => $item['name'],
            'url' => $item['url']
        ];
    }
    return $tvList;
}

// 保存数据：先通过临时ID找到对应索引，再更新urls数组
function saveTvData($tvList) {
    // 先读取原始的tv.json数据（包含urls结构）
    $json = file_get_contents(JSON_FILE_PATH);
    $originalData = json_decode($json, true) ?: ['urls' => []];
    $originalUrls = $originalData['urls'] ?: [];
    
    // 处理新增/修改：将带临时ID的tvList转换为纯urls格式（不含ID）
    $newUrls = [];
    foreach ($tvList as $item) {
        $newUrls[] = [
            'url' => $item['url'],
            'name' => $item['name']
        ];
    }
    
    // 保存到tv.json（保持目标格式）
    $finalData = ['urls' => $newUrls];
    $json = json_encode($finalData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    return file_put_contents(JSON_FILE_PATH, $json);
}

// 处理排序（上移下移）
function reorderTvData($id, $direction) {
    $tvList = getTvData();
    $index = -1;
    
    // 找到当前元素的索引
    foreach ($tvList as $i => $item) {
        if ($item['id'] == $id) {
            $index = $i;
            break;
        }
    }
    
    if ($index == -1) return false;
    
    // 根据方向交换位置
    if ($direction == 'up' && $index > 0) {
        // 上移：与前一个元素交换
        $temp = $tvList[$index - 1];
        $tvList[$index - 1] = $tvList[$index];
        $tvList[$index] = $temp;
    } elseif ($direction == 'down' && $index < count($tvList) - 1) {
        // 下移：与后一个元素交换
        $temp = $tvList[$index + 1];
        $tvList[$index + 1] = $tvList[$index];
        $tvList[$index] = $temp;
    } else {
        return false; // 无法移动
    }
    
    // 重新编号并保存
    $fixedList = [];
    foreach ($tvList as $i => $item) {
        $fixedList[] = [
            'id' => $i + 1,
            'name' => $item['name'],
            'url' => $item['url']
        ];
    }
    
    return saveTvData($fixedList);
}

// 处理拖拽排序
function dragReorderTvData($fromId, $toId) {
    $tvList = getTvData();
    $fromIndex = -1;
    $toIndex = -1;
    
    // 找到两个元素的索引
    foreach ($tvList as $i => $item) {
        if ($item['id'] == $fromId) $fromIndex = $i;
        if ($item['id'] == $toId) $toIndex = $i;
        if ($fromIndex != -1 && $toIndex != -1) break;
    }
    
    if ($fromIndex == -1 || $toIndex == -1 || $fromIndex == $toIndex) {
        return false;
    }
    
    // 移除并插入元素
    $movedItem = $tvList[$fromIndex];
    array_splice($tvList, $fromIndex, 1);
    array_splice($tvList, $toIndex, 0, [$movedItem]);
    
    // 重新编号并保存
    $fixedList = [];
    foreach ($tvList as $i => $item) {
        $fixedList[] = [
            'id' => $i + 1,
            'name' => $item['name'],
            'url' => $item['url']
        ];
    }
    
    return saveTvData($fixedList);
}
?>