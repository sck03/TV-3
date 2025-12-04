<?php
session_start(); // 提前开启SESSION，用于存储提示信息
$version = '1.0.0';
$loginError = false;
$adminPassword = '你的登录密码'; // 替换为你的登录密码
$jsonurl = '你的订阅地址'; // 替换为你的订阅地址

// 处理登录提交
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['password']) && $_POST['password'] === $adminPassword) {
        setcookie('tv_admin_login', md5($adminPassword), time() + 86400, '/');
        header('Location: index.php');
        exit;
    } else {
        $loginError = true; // 仅密码错误时显示提示
    }
}

// 处理退出登录
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    setcookie('tv_admin_login', '', time() - 3600, '/'); // 清除Cookie
    header('Location: index.php');
    exit;
}

// 未登录状态
if (!isset($_COOKIE['tv_admin_login']) || $_COOKIE['tv_admin_login'] !== md5($adminPassword)) {
    // 用PHP变量存储样式，避免引号冲突
    $toastStyle = $loginError ? 'display: block;' : 'display: none;';
    echo '
    <!DOCTYPE html>
    <html lang="zh-CN">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>管理员登录 - 影视仓多源管理系统</title>
        <link rel="stylesheet" href="style.css">
    </head>
    <body>
        <div class="container" style="max-width: 400px; margin-top: 100px;">
            <div class="title-container">
                <h2>影视仓多源管理系统 v'.$version.'</h2>
            </div>
            <div style="padding: 0 20px;">
            <div style="margin-top: 30px; padding: 15px; background: #f0f8ff; border-radius: 4px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;">
                <p style="color: #333; font-weight: bold;">影视仓配置地址：</p><a href="' . $jsonurl . '" target="_blank" style="color: #007bff; text-decoration: none;">' . $jsonurl . '</a></div>
            </div>
            <div style="padding: 20px;">
                <form method="post">
                    <div class="form-group">
                        <label for="password">管理员密码</label>
                        <input type="password" id="password" name="password" required placeholder="请输入密码">
                    </div>
                    <button type="submit" class="btn" style="width: 100%;">登录</button>
                </form>
            </div>
        </div>

        <!-- 登录错误提示 -->
        <div class="toast toast-error" id="loginToast" style="' . $toastStyle . '">
            密码错误，请重新输入！
        </div>

        <script>
            // 登录错误提示逻辑
            const loginToast = document.getElementById("loginToast");
            if (' . ($loginError ? 'true' : 'false') . ') {
                loginToast.classList.add("show");
                loginToast.style.opacity = "1";
                loginToast.style.transform = "translateY(0)";
                setTimeout(() => {
                    loginToast.classList.remove("show");
                    loginToast.style.opacity = "0";
                    loginToast.style.transform = "translateY(-50px)";
                }, 3000);
            }
        </script>
    </body>
    </html>';
    exit;
}

require 'config.php';

function backupTvJson() {
    $sourceFile = JSON_FILE_PATH;
    $backupDir = '../tv_backups/';

    if (!file_exists($backupDir)) {
        mkdir($backupDir, 0755, true);
        file_put_contents($backupDir . 'index.html', '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body></body></html>');
    }

    $timestamp = date('YmdHis');
    $backupFile = $backupDir . "tv_backup_{$timestamp}.json";

    if (copy($sourceFile, $backupFile)) {
        return [
            'success' => true,
            'msg' => '备份成功！'
        ];
    } else {
        return [
            'success' => false,
            'msg' => '备份失败，请检查文件夹权限！'
        ];
    }
}

// 获取所有备份文件（按时间倒序）
function getAllBackupFiles() {
    $backupDir = '../tv_backups/';
    $backupFiles = [];

    if (!file_exists($backupDir) || !is_dir($backupDir)) {
        return $backupFiles;
    }

    $files = scandir($backupDir);
    foreach ($files as $file) {
        if (strpos($file, 'tv_backup_') === 0 && pathinfo($file, PATHINFO_EXTENSION) === 'json') {
            $filePath = $backupDir . $file;
            $backupFiles[] = [
                'filename' => $file,
                'size' => round(filesize($filePath) / 1024, 2),
                'mtime' => filemtime($filePath),
                'filePath' => $filePath
            ];
        }
    }

    usort($backupFiles, function($a, $b) {
        return $b['mtime'] - $a['mtime'];
    });

    return $backupFiles;
}

// 删除备份文件
function deleteBackupFile($filename) {
    $backupDir = '../tv_backups/';
    $filePath = $backupDir . $filename;

    if (file_exists($filePath) && strpos($filename, 'tv_backup_') === 0 && pathinfo($filename, PATHINFO_EXTENSION) === 'json') {
        unlink($filePath);
        return true;
    }
    return false;
}

// 处理 AJAX 删除备份请求
if (isset($_POST['action']) && $_POST['action'] === 'ajax_delete_backup' && isset($_POST['filename'])) {
    $filename = urldecode($_POST['filename']);
    $result = [
        'success' => false,
        'msg' => '删除失败！'
    ];

    if (deleteBackupFile($filename)) {
        $result['success'] = true;
        $result['msg'] = '备份文件删除成功！';
        $result['newCount'] = count(getAllBackupFiles());
    }

    header('Content-Type: application/json');
    echo json_encode($result);
    exit;
}

// 读取SESSION中的提示信息（所有操作的提示都存在这里）
$toastMsg = $_SESSION['toast_msg'] ?? '';
$toastType = $_SESSION['toast_type'] ?? '';
// 读取后立即清除，避免刷新重复显示
unset($_SESSION['toast_msg'], $_SESSION['toast_type']);

// ######################## 所有操作统一用POST处理 ########################
// 1. 处理上下移动排序（POST）
if (isset($_POST['action']) && in_array($_POST['action'], ['move_up', 'move_down']) && isset($_POST['id'])) {
    $id = intval($_POST['id']);
    $direction = $_POST['action'] == 'move_up' ? 'up' : 'down';

    if (reorderTvData($id, $direction)) {
        $_SESSION['toast_msg'] = $direction == 'up' ? '上移成功！' : '下移成功！';
        $_SESSION['toast_type'] = 'success';
    } else {
        $_SESSION['toast_msg'] = $direction == 'up' ? '上移失败！' : '下移失败！';
        $_SESSION['toast_type'] = 'error';
    }
    header('Location: index.php');
    exit;
}

// 2. 处理删除操作（POST）
if (isset($_POST['action']) && $_POST['action'] === 'delete' && isset($_POST['id'])) {
    $id = intval($_POST['id']);
    $tvList = getTvData();
    $newTvList = [];
    foreach ($tvList as $item) {
        if ($item['id'] !== $id) $newTvList[] = $item;
    }
    $fixedList = [];
    foreach ($newTvList as $i => $item) {
        $fixedList[] = ['id' => $i+1, 'name' => $item['name'], 'url' => $item['url']];
    }
    if (saveTvData($fixedList)) {
        $_SESSION['toast_msg'] = '删除成功！';
        $_SESSION['toast_type'] = 'success';
    } else {
        $_SESSION['toast_msg'] = '删除失败！';
        $_SESSION['toast_type'] = 'error';
    }
    header('Location: index.php');
    exit;
}

// 3. 处理备份操作（POST）
if (isset($_POST['action']) && $_POST['action'] === 'backup') {
    $backupResult = backupTvJson();
    $_SESSION['toast_msg'] = $backupResult['msg'];
    $_SESSION['toast_type'] = $backupResult['success'] ? 'success' : 'error';
    header('Location: index.php');
    exit;
}

// 4. 处理下载备份操作（POST+文件流）
if (isset($_POST['action']) && $_POST['action'] === 'download_backup' && isset($_POST['file'])) {
    $filename = urldecode($_POST['file']);
    $backupDir = '../tv_backups/';
    $filePath = $backupDir . $filename;

    if (file_exists($filePath) && strpos($filename, 'tv_backup_') === 0 && pathinfo($filename, PATHINFO_EXTENSION) === 'json') {
        // 直接输出文件流，不重定向（下载不影响地址栏）
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($filePath));
        readfile($filePath);
        exit;
    } else {
        $_SESSION['toast_msg'] = '备份文件不存在或非法！';
        $_SESSION['toast_type'] = 'error';
        header('Location: index.php');
        exit;
    }
}

// 5. 处理新增/编辑操作（POST）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['action'])) {
    $name = trim($_POST['name'] ?? '');
    $url = trim($_POST['url'] ?? '');
    if (empty($name) || empty($url)) {
        $_SESSION['toast_msg'] = '名称和地址不能为空！';
        $_SESSION['toast_type'] = 'error';
        header('Location: index.php');
        exit;
    }

    if (isset($_POST['id']) && $_POST['id'] > 0) {
        $id = intval($_POST['id']);
        $tvList = getTvData();
        $updated = false;
        foreach ($tvList as &$item) {
            if ($item['id'] === $id) {
                $item['name'] = $name;
                $item['url'] = $url;
                $updated = true;
                break;
            }
        }
        unset($item);
        if ($updated && saveTvData($tvList)) {
            $_SESSION['toast_msg'] = '修改成功！';
            $_SESSION['toast_type'] = 'success';
        } else {
            $_SESSION['toast_msg'] = '修改失败！';
            $_SESSION['toast_type'] = 'error';
        }
    } else {
        $tvList = getTvData();
        $maxId = 0;
        foreach ($tvList as $item) {
            if ($item['id'] > $maxId) $maxId = $item['id'];
        }
        $tvList[] = ['id' => $maxId+1, 'name' => $name, 'url' => $url];
        if (saveTvData($tvList)) {
            $_SESSION['toast_msg'] = '新增成功！';
            $_SESSION['toast_type'] = 'success';
        } else {
            $_SESSION['toast_msg'] = '新增失败！';
            $_SESSION['toast_type'] = 'error';
        }
    }
    header('Location: index.php');
    exit;
}

// 6. 拖拽排序（AJAX+无刷新）
if (isset($_GET['action']) && $_GET['action'] === 'drag_reorder' && isset($_GET['from']) && isset($_GET['to'])) {
    $fromId = intval($_GET['from']);
    $toId = intval($_GET['to']);

    $result = [
        'success' => false,
        'msg' => '排序失败'
    ];

    if (dragReorderTvData($fromId, $toId)) {
        $result['success'] = true;
        $result['msg'] = '排序成功';
    }

    header('Content-Type: application/json');
    echo json_encode($result);
    exit;
}

// 获取数据和备份文件列表
$tvList = getTvData();
$allBackupFiles = getAllBackupFiles();
$editData = null;
if (isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    foreach ($tvList as $item) {
        if ($item['id'] === $id) {
            $editData = $item;
            break;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>影视仓多源管理系统-@imwzh</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="container">
        <!-- 退出登录按钮 -->
        <button class="logout-btn" onclick="if(confirm('确定要退出登录吗？')) window.location.href='index.php?action=logout'; return false;">
    退出登录
        </button>

        <!-- 标题居中 -->
        <div class="title-container">
            <h1>影视仓多源管理系统</h1>
        </div>

        <!-- 列表标题+新增按钮 -->
        <div class="list-header">
            <h2>数据列表（共 <?php echo count($tvList); ?> 条）</h2>
            <button class="btn" id="addBtn">新增数据</button>
        </div>

        <!-- 数据列表 -->
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>名称</th>
                    <th>地址</th>
                    <th>操作</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($tvList)): ?>
                    <tr>
                        <td colspan="4" style="text-align: center; color: #666;">暂无数据，请点击上方「新增数据」</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($tvList as $item): ?>
                        <tr>
                            <td><?php echo $item['id']; ?></td>
                            <td><?php echo htmlspecialchars($item['name']); ?></td>
                            <td>
                                <a href="<?php echo htmlspecialchars($item['url']); ?>" target="_blank" style="color: #007bff; text-decoration: none;">
                                    <?php echo htmlspecialchars($item['url']); ?>
                                </a>
                            </td>
                            <td class="action-buttons">
                                <div class="lin1">
                                <!-- 第一行：上移、下移（POST表单） -->
                                <form method="post" action="index.php" onsubmit="return confirm('确定要将此项上移吗？')" style="display: inline;">
                                    <input type="hidden" name="action" value="move_up">
                                    <input type="hidden" name="id" value="<?php echo $item['id']; ?>">
                                    <button type="submit" class="btn btn-sm move-up" <?php echo $item['id'] == 1 ? 'disabled title="已经是第一条"' : ''; ?>>
                                        上移
                                    </button>
                                </form>
                                <form method="post" action="index.php" onsubmit="return confirm('确定要将此项下移吗？')" style="display: inline;">
                                    <input type="hidden" name="action" value="move_down">
                                    <input type="hidden" name="id" value="<?php echo $item['id']; ?>">
                                    <button type="submit" class="btn btn-sm move-down" <?php echo $item['id'] == count($tvList) ? 'disabled title="已经是最后一条"' : ''; ?>>
                                        下移
                                    </button>
                                </form>
                                </div>
                                <div class="lin2">
                                <!-- 第二行：编辑、删除（POST表单） -->
                                <button class="btn btn-sm editBtn"
                                        data-id="<?php echo $item['id']; ?>"
                                        data-name="<?php echo htmlspecialchars($item['name']); ?>"
                                        data-url="<?php echo htmlspecialchars($item['url']); ?>">
                                    编辑
                                </button>
                                <form method="post" action="index.php" onsubmit="return confirm('确定要删除吗？')" style="display: inline;">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?php echo $item['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-danger">删除</button>
                                </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <!-- JSON地址提示 -->
        <div style="margin-top: 30px; padding: 15px; background: #f0f8ff; border-radius: 4px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;">
            <div>
                <p style="color: #333; font-weight: bold; margin-bottom: 5px;">JSON输出地址：</p>
                <a href="<?php echo $jsonurl; ?>" target="_blank" style="color: #007bff; text-decoration: none;"><?php echo $jsonurl; ?></a>
            </div>
            <div style="display: flex; gap: 10px;">
                <!-- 备份按钮（POST表单） -->
                <form method="post" action="index.php" onsubmit="return confirm('确定要备份 tv.json 吗？')" style="display: inline;">
                    <input type="hidden" name="action" value="backup">
                    <button type="submit" class="btn">备份 tv.json</button>
                </form>
                <!-- 下载备份文件按钮（触发弹窗） -->
                <button class="btn btn-success" id="backupListBtn" <?php echo empty($allBackupFiles) ? 'disabled title="暂无备份文件"' : ''; ?>>
                    下载备份文件
                </button>
            </div>
        </div>

    <!-- 新增/编辑弹窗 -->
    <div class="modal-overlay" id="modalOverlay">
        <div class="modal">
            <div class="modal-header">
                <h3 class="modal-title" id="modalTitle">新增数据</h3>
                <button class="modal-close" id="modalClose">&times;</button>
            </div>
            <form method="post" action="index.php" id="modalForm">
                <input type="hidden" name="id" id="formId" value="0">
                <div class="form-group">
                    <label for="formName">名称</label>
                    <input type="text" id="formName" name="name" required>
                </div>
                <div class="form-group">
                    <label for="formUrl">地址（URL）</label>
                    <input type="text" id="formUrl" name="url" required>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn">保存</button>
                    <button type="button" class="btn btn-danger" id="cancelBtn">取消</button>
                </div>
            </form>
        </div>
    </div>

    <!-- 操作提示框 -->
    <div class="toast" id="toast" style="cursor: pointer;">
        <span id="toastContent"></span>
    </div>

    <!-- 备份列表弹窗 -->
    <div class="modal-overlay" id="backupModalOverlay">
        <div class="modal" style="max-width: 800px;">
            <div class="modal-header">
                <h3 class="modal-title">历史备份文件（共 <span id="backupCount"><?php echo count($allBackupFiles); ?></span> 个）</h3>
                <button class="modal-close" id="backupModalClose">&times;</button>
            </div>
            <div style="max-height: 400px; overflow-y: auto; padding: 10px 0;">
                <?php if (empty($allBackupFiles)): ?>
                    <div style="text-align: center; padding: 30px; color: #666;">
                        暂无备份文件，请先点击「备份 tv.json」创建备份
                    </div>
                <?php else: ?>
                    <table style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr style="background: #e9ecef;">
                                <th style="padding: 10px; text-align: left; border-bottom: 1px solid #ddd; font-size: 14px;">备份文件名</th>
                                <th style="padding: 10px; text-align: center; border-bottom: 1px solid #ddd; font-size: 14px;">文件大小</th>
                                <th style="padding: 10px; text-align: center; border-bottom: 1px solid #ddd; font-size: 14px;">备份时间</th>
                                <th style="padding: 10px; text-align: center; border-bottom: 1px solid #ddd; font-size: 14px;">操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($allBackupFiles as $file): ?>
                                <tr>
                                    <td style="padding: 10px; border-bottom: 1px solid #eee; font-size: 14px;">
                                        <?php echo htmlspecialchars($file['filename']); ?>
                                    </td>
                                    <td style="padding: 10px; border-bottom: 1px solid #eee; font-size: 14px; text-align: center;">
                                        <?php echo $file['size'] . ' KB'; ?>
                                    </td>
                                    <td style="padding: 10px; border-bottom: 1px solid #eee; font-size: 14px; text-align: center;">
                                        <?php echo date('Y-m-d H:i:s', $file['mtime']); ?>
                                    </td>
                                    <td style="padding: 10px; border-bottom: 1px solid #eee; font-size: 14px; text-align: center;">
                                        <!-- 下载备份文件（POST表单） -->
                                        <form method="post" action="index.php" style="display: inline;">
                                            <input type="hidden" name="action" value="download_backup">
                                            <input type="hidden" name="file" value="<?php echo urlencode($file['filename']); ?>">
                                            <button type="submit" class="btn btn-sm btn-success" style="margin-right: 5px;">下载</button>
                                        </form>
                                        <!-- 删除备份（AJAX） -->
                                        <button class="btn btn-sm btn-danger delete-backup-btn"
                                                data-filename="<?php echo urlencode($file['filename']); ?>"
                                                onclick="return confirm('确定要删除该备份文件吗？删除后无法恢复！')">
                                            删除
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" id="backupModalCancel">关闭</button>
            </div>
        </div>
    </div>

</div>

<script>
// 整合所有提示数据
const toastData = {
    msg: "<?php echo addslashes(trim($toastMsg)); ?>",
    type: "<?php echo trim($toastType) ?: 'error'; ?>",
    hasMsg: "<?php echo !empty(trim($toastMsg)) ? 'true' : 'false'; ?>"
};

window.onload = function() {
    // 1. 处理提示框
    const toast = document.getElementById('toast');
    const toastContent = document.getElementById('toastContent');

    toast.className = 'toast';
    toast.style.opacity = '0';
    toast.style.transform = 'translateY(-50px)';
    toast.style.display = 'none';

    if (toastData.hasMsg === 'true' && toastData.msg.trim() !== '') {
        toastContent.textContent = toastData.msg;
        toast.classList.add(toastData.type === 'success' ? 'toast-success' : 'toast-error');

        toast.style.display = 'block';
        setTimeout(() => {
            toast.classList.add('show');
            toast.style.opacity = '1';
            toast.style.transform = 'translateY(0)';
        }, 100);

        const isBackupRelated = "<?php echo isset($_POST['action']) && in_array($_POST['action'], ['backup', 'download_backup', 'ajax_delete_backup']) ? 'true' : 'false'; ?>";
        const delay = isBackupRelated === 'true' ? 5000 : 3000;

        setTimeout(() => {
            toast.classList.remove('show');
            toast.style.opacity = '0';
            toast.style.transform = 'translateY(-50px)';
            setTimeout(() => {
                toast.style.display = 'none';
            }, 500);
        }, delay);
    }

    // 2. 处理编辑弹窗自动打开
    <?php if ($editData): ?>
        const modalOverlay = document.getElementById('modalOverlay');
        const modalTitle = document.getElementById('modalTitle');
        const formId = document.getElementById('formId');
        const formName = document.getElementById('formName');
        const formUrl = document.getElementById('formUrl');

        modalTitle.textContent = '编辑数据';
        formId.value = <?php echo $editData['id']; ?>;
        formName.value = '<?php echo addslashes(htmlspecialchars($editData['name'])); ?>';
        formUrl.value = '<?php echo addslashes(htmlspecialchars($editData['url'])); ?>';
        modalOverlay.style.display = 'flex';
    <?php endif; ?>

    // 3. 弹窗控制逻辑
    const modalOverlay = document.getElementById('modalOverlay');
    const addBtn = document.getElementById('addBtn');
    const modalClose = document.getElementById('modalClose');
    const cancelBtn = document.getElementById('cancelBtn');
    const editBtns = document.querySelectorAll('.editBtn');

    addBtn.addEventListener('click', () => {
        document.getElementById('modalTitle').textContent = '新增数据';
        document.getElementById('formId').value = 0;
        document.getElementById('formName').value = '';
        document.getElementById('formUrl').value = '';
        modalOverlay.style.display = 'flex';
    });

    editBtns.forEach(btn => {
        btn.addEventListener('click', () => {
            const id = btn.getAttribute('data-id');
            const name = btn.getAttribute('data-name');
            const url = btn.getAttribute('data-url');

            document.getElementById('modalTitle').textContent = '编辑数据';
            document.getElementById('formId').value = id;
            document.getElementById('formName').value = name;
            document.getElementById('formUrl').value = url;
            modalOverlay.style.display = 'flex';
        });
    });

    function closeModal() {
        modalOverlay.style.display = 'none';
    }
    modalClose.addEventListener('click', closeModal);
    cancelBtn.addEventListener('click', closeModal);
    modalOverlay.addEventListener('click', (e) => {
        if (e.target === modalOverlay) closeModal();
    });

    // 4. 备份列表弹窗控制逻辑
    const backupModalOverlay = document.getElementById('backupModalOverlay');
    const backupListBtn = document.getElementById('backupListBtn');
    const backupModalClose = document.getElementById('backupModalClose');
    const backupModalCancel = document.getElementById('backupModalCancel');

    backupListBtn.addEventListener('click', () => {
        backupModalOverlay.style.display = 'flex';
    });

    function closeBackupModal() {
        backupModalOverlay.style.display = 'none';
    }
    backupModalClose.addEventListener('click', closeBackupModal);
    backupModalCancel.addEventListener('click', closeBackupModal);
    backupModalOverlay.addEventListener('click', (e) => {
        if (e.target === backupModalOverlay) closeBackupModal();
    });

    // 5. AJAX 无刷新删除备份文件
    document.querySelectorAll('.delete-backup-btn').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            const filename = decodeURIComponent(this.getAttribute('data-filename'));
            const $thisBtn = this;
            const $tr = $thisBtn.closest('tr');
            const backupCountEl = document.getElementById('backupCount');

            fetch('index.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=ajax_delete_backup&filename=${encodeURIComponent(filename)}`
            })
            .then(response => response.json())
            .then(data => {
                const toast = document.getElementById('toast');
                const toastContent = document.getElementById('toastContent');
                toastContent.textContent = data.msg;
                toast.className = 'toast';
                toast.classList.add(data.success ? 'toast-success' : 'toast-error');
                toast.style.display = 'block';
                setTimeout(() => {
                    toast.classList.add('show');
                    toast.style.opacity = '1';
                    toast.style.transform = 'translateY(0)';
                }, 100);

                setTimeout(() => {
                    toast.classList.remove('show');
                    toast.style.opacity = '0';
                    toast.style.transform = 'translateY(-50px)';
                    setTimeout(() => {
                        toast.style.display = 'none';
                    }, 500);
                }, 3000);

                if (data.success) {
                    $tr.style.opacity = '0';
                    $tr.style.transition = 'opacity 0.3s ease';
                    setTimeout(() => {
                        $tr.remove();
                    }, 300);

                    backupCountEl.textContent = data.newCount;

                    const backupTable = document.querySelector('#backupModalOverlay table');
                    if (data.newCount === 0 && backupTable) {
                        backupTable.parentNode.innerHTML = `
                            <div style="text-align: center; padding: 30px; color: #666;">
                                暂无备份文件，请先点击「备份 tv.json」创建备份
                            </div>
                        `;
                        document.getElementById('backupListBtn').disabled = true;
                        document.getElementById('backupListBtn').setAttribute('title', '暂无备份文件');
                    }
                }
            })
            .catch(error => {
                console.error('删除失败：', error);
                const toast = document.getElementById('toast');
                const toastContent = document.getElementById('toastContent');
                toastContent.textContent = '网络错误，删除失败！';
                toast.className = 'toast toast-error';
                toast.style.display = 'block';
                setTimeout(() => {
                    toast.classList.add('show');
                }, 100);
                setTimeout(() => {
                    toast.classList.remove('show');
                    setTimeout(() => {
                        toast.style.display = 'none';
                    }, 500);
                }, 3000);
            });
        });
    });


    // 6. 拖拽排序功能
    let draggedItem = null;
    const tableBody = document.querySelector('table tbody');

    document.querySelectorAll('table tbody tr').forEach(row => {
        row.setAttribute('draggable', 'true');

        row.addEventListener('dragstart', function() {
            draggedItem = this;
            setTimeout(() => {
                this.classList.add('dragging');
            }, 0);
        });

        row.addEventListener('dragend', function() {
            this.classList.remove('dragging');
            draggedItem = null;
        });

        row.addEventListener('dragover', function(e) {
            e.preventDefault();
        });

        row.addEventListener('dragenter', function(e) {
            e.preventDefault();
            if (this !== draggedItem) {
                this.style.backgroundColor = '#e9ecef';
            }
        });

        row.addEventListener('dragleave', function() {
            this.style.backgroundColor = '';
        });

        row.addEventListener('drop', function(e) {
            e.preventDefault();
            this.style.backgroundColor = '';

            if (this !== draggedItem) {
                const draggedId = draggedItem.querySelector('.action-buttons .editBtn').getAttribute('data-id');
                const targetId = this.querySelector('.action-buttons .editBtn').getAttribute('data-id');

                fetch(`index.php?action=drag_reorder&from=${draggedId}&to=${targetId}`)
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            window.location.reload();
                        } else {
                            showToast('排序失败，请重试', 'error');
                        }
                    });
            }
        });
    });

    // 显示提示框
    function showToast(message, type) {
        const toast = document.getElementById('toast');
        const toastContent = document.getElementById('toastContent');

        toastContent.textContent = message;
        toast.className = 'toast';
        toast.classList.add(type === 'success' ? 'toast-success' : 'toast-error');
        toast.style.display = 'block';

        setTimeout(() => {
            toast.classList.add('show');
        }, 100);

        setTimeout(() => {
            toast.classList.remove('show');
            setTimeout(() => {
                toast.style.display = 'none';
            }, 500);
        }, 3000);
    }
};
</script>
</body>
</html>