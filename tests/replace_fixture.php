<?php
// Минимальный макет WP API: выполняет настоящие функции планирования и удаления.
define('WP_CLI', true);
define('ARRAY_A', 'ARRAY_A');
class WP_CLI {
    public static function error($m) { throw new RuntimeException($m); }
    public static function log($m) {}
}
class FakeDB {
    public $posts = 'wp_posts';
    public $last_error = '';
    public $query = '';
    public function get_results($sql, $format) {
        $this->query = $sql;
        return array_values($GLOBALS['posts']);
    }
}
$wpdb = new FakeDB();
$posts = array(
    4=>array('ID'=>4,'post_type'=>'wp_navigation','post_name'=>'navigation','post_status'=>'publish'),
    7=>array('ID'=>7,'post_type'=>'attachment','post_name'=>'photo','post_status'=>'inherit'),
    10=>array('ID'=>10,'post_type'=>'page','post_name'=>'old','post_status'=>'publish'),
    11=>array('ID'=>11,'post_type'=>'revision','post_name'=>'old-revision','post_status'=>'inherit')
);
$events = array();
function is_wp_error($v) { return false; }
function get_post($id) { return isset($GLOBALS['posts'][$id]) ? (object)$GLOBALS['posts'][$id] : null; }
function wp_delete_post($id, $force) {
    $GLOBALS['events'][] = array('post', $id);
    unset($GLOBALS['posts'][$id]);
    if ($id === 10) { unset($GLOBALS['posts'][11]); }
    return true;
}
function wp_delete_attachment($id, $force) {
    $GLOBALS['events'][] = array('attachment', $id);
    unset($GLOBALS['posts'][$id]);
    return true;
}
function get_terms($args) { return $args['taxonomy'] === 'category' ? array(1,2) : array(8); }
function get_option($key) { return 1; }
function wp_delete_term($id, $taxonomy) { $GLOBALS['events'][] = array('term', $taxonomy, $id); return true; }
function update_option($key, $value) { $GLOBALS['events'][] = array('option', $key, $value); }
function set_theme_mod($key, $value) { $GLOBALS['events'][] = array('theme_mod', $key, $value); }
$code = file_get_contents($argv[2]);
$prefix = explode("\n\$mode = ", $code, 2)[0];
eval(substr($prefix, 5));
try {
    $rows = dtp_stock(false, $argv[1] !== 'strict');
    if ($argv[1] === 'delete') { dtp_clear_content($rows, true); }
    echo json_encode(array('rows'=>$rows,'remaining'=>array_keys($posts),'events'=>$events,'query'=>$wpdb->query));
} catch (RuntimeException $e) {
    echo json_encode(array('error'=>$e->getMessage(),'remaining'=>array_keys($posts),'events'=>$events));
}
