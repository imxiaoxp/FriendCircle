<?php

/**
 * 点赞 AJAX 接口
 *
 * POST /usr/themes/FriendCircle/like.php
 * Body: cid=<文章ID>&cancel=<0|1>（cancel=1 为取消点赞）
 * Response: {"count": 数字}
 */

$config = dirname(dirname(dirname(__DIR__))) . '/config.inc.php';
if (!file_exists($config)) {
    http_response_code(500);
    exit(json_encode(['error' => 'Config not found']));
}
require_once $config;

$db = \Typecho\Db::get();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['error' => 'Method not allowed']));
}

$cid = isset($_POST['cid']) ? (int) $_POST['cid'] : 0;
if ($cid <= 0) {
    exit(json_encode(['error' => 'Invalid cid']));
}

$cancel = isset($_POST['cancel']) && '1' === $_POST['cancel'];

$field = $db->fetchRow(
    $db->select('str_value')->from('table.fields')
        ->where('cid = ?', $cid)->where('name = ?', 'agree')
);

if ($cancel) {
    $count = $field ? max(0, (int) $field['str_value'] - 1) : 0;
    if ($field) {
        $db->query(
            $db->update('table.fields')
                ->rows(['str_value' => (string) $count])
                ->where('cid = ?', $cid)
                ->where('name = ?', 'agree')
        );
    }
} elseif (!$field) {
    $db->query($db->insert('table.fields')->rows([
        'cid'         => $cid,
        'name'        => 'agree',
        'type'        => 'str',
        'str_value'   => '1',
        'int_value'   => 0,
        'float_value' => 0,
    ]));
    $count = 1;
} else {
    $count = (int) $field['str_value'] + 1;
    $db->query(
        $db->update('table.fields')
            ->rows(['str_value' => (string) $count])
            ->where('cid = ?', $cid)
            ->where('name = ?', 'agree')
    );
}

header('Content-Type: application/json');
echo json_encode(['count' => $count]);
