<?php
/** Internal WP-CLI helper. Run only through install.py. */
if (!defined('WP_CLI') || !WP_CLI) { exit(1); }
function dtp_fail($message) { WP_CLI::error($message); }
function dtp_ok($value, $context) {
    if (is_wp_error($value)) { dtp_fail($context . ': ' . $value->get_error_message()); }
    return $value;
}
function dtp_read($path) {
    if (!is_readable($path)) { dtp_fail('Нет файла: ' . $path); }
    $value = json_decode(file_get_contents($path), true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($value)) { dtp_fail('Некорректный JSON: ' . $path); }
    return $value;
}
function dtp_page($slug) {
    if ($slug === '') { return 0; }
    global $wpdb;
    $ids = $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type='page' AND post_name=%s AND post_status NOT IN ('trash','auto-draft')", $slug));
    if ($wpdb->last_error) { dtp_fail('Ошибка запроса страницы: ' . $wpdb->last_error); }
    if (count($ids) !== 1) { dtp_fail('Ожидалась одна страница со slug ' . $slug . ', найдено: ' . count($ids)); }
    return (int)$ids[0];
}
function dtp_attachment($file) {
    if ($file === '') { return 0; }
    global $wpdb;
    $ids = $wpdb->get_col($wpdb->prepare("SELECT DISTINCT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id=p.ID WHERE p.post_type='attachment' AND m.meta_key='_wp_attached_file' AND m.meta_value=%s", $file));
    if ($wpdb->last_error || count($ids) !== 1) { dtp_fail('Неоднозначный/отсутствующий медиафайл: ' . $file); }
    return (int)$ids[0];
}
function dtp_replace($value, $old, $new) {
    if (is_string($value)) { return str_replace($old, $new, $value); }
    if (is_array($value)) {
        foreach ($value as $key => $v) { $value[$key] = dtp_replace($v, $old, $new); }
    }
    return $value;
}
function dtp_stock($allowed, $replace = false) {
    global $wpdb;
    $where = $replace ? '' : " WHERE post_type NOT IN ('revision','customize_changeset','user_request','wp_global_styles') AND post_status <> 'auto-draft'";
    $rows = $wpdb->get_results("SELECT ID,post_type,post_name,post_status FROM {$wpdb->posts}" . $where . ' ORDER BY ID', ARRAY_A);
    if ($wpdb->last_error) { dtp_fail('Ошибка чтения контента: ' . $wpdb->last_error); }
    $stock = array();
    foreach ($rows as $row) {
        if ($replace) { $stock[] = $row; continue; }
        $standard = ($row['post_type'] === 'post' && $row['post_name'] === 'hello-world') ||
            ($row['post_type'] === 'page' && in_array($row['post_name'], array('sample-page','privacy-policy'), true));
        if (!$standard || !$allowed) {
            dtp_fail('Цель содержит контент: ID=' . $row['ID'] . ', type=' . $row['post_type'] . ', slug=' . $row['post_name'] . '. Для замены содержимого есть --replace-content; для штатных записей --remove-stock-content.');
        }
        $stock[] = $row;
    }
    return $stock;
}
function dtp_clear_content($rows, $replace) {
    foreach ($rows as $row) {
        $id = (int)$row['ID'];
        // Удаление родителя может уже удалить его ревизии.
        if (!get_post($id)) { continue; }
        $deleted = $row['post_type'] === 'attachment' ? wp_delete_attachment($id, true) : wp_delete_post($id, true);
        if (!$deleted || get_post($id)) { dtp_fail('Не удалось удалить ID=' . $id); }
        WP_CLI::log('Удалена запись ID=' . $id . ', type=' . $row['post_type']);
    }
    if ($replace) {
        foreach (array('nav_menu', 'post_tag', 'category') as $taxonomy) {
            $ids = dtp_ok(get_terms(array('taxonomy'=>$taxonomy, 'hide_empty'=>false, 'fields'=>'ids')), 'Список термов');
            foreach ($ids as $id) {
                if ($taxonomy === 'category' && (int)$id === (int)get_option('default_category')) { continue; }
                $deleted = dtp_ok(wp_delete_term((int)$id, $taxonomy), 'Удаление терма');
                if (!$deleted) { dtp_fail('Не удалён терм ' . $taxonomy . ':' . $id); }
            }
        }
        foreach (array('page_on_front','page_for_posts','wp_page_for_privacy_policy','site_icon','site_logo') as $option) { update_option($option, 0); }
        set_theme_mod('nav_menu_locations', array());
    }
}

$mode = $args[0] ?? '';
$d = dtp_read($args[1] ?? '');
$items = dtp_read($args[2] ?? '');
$allowed = ($args[3] ?? '') === 'yes';
$replace = ($args[3] ?? '') === 'replace';
global $wpdb;
if (is_multisite()) { dtp_fail('Этот установщик предназначен для одиночного WordPress, не multisite'); }
if ($mode === 'preflight' || $mode === 'prepare') {
    foreach (array('source_url','blogname','blogdescription','pages','options','site_icon','site_logo','categories','menus','plugins','themes','counts') as $key) {
        if (!array_key_exists($key, $d)) { dtp_fail('settings.json: отсутствует ' . $key); }
    }
    if (!is_string($d['source_url']) || !preg_match('~^https?://[^\s]+$~D', $d['source_url'])) { dtp_fail('Некорректный source_url'); }
    foreach (array('front','posts','privacy') as $key) {
        if (!isset($d['pages'][$key]) || !is_string($d['pages'][$key])) { dtp_fail('Некорректный pages.' . $key); }
    }
    foreach (array('permalink_structure','posts_per_page','date_format','time_format','start_of_week') as $key) {
        if (!array_key_exists($key, $d['options'])) { dtp_fail('Отсутствует options.' . $key); }
    }
    if ((int)$d['options']['posts_per_page'] < 1 || (int)$d['options']['start_of_week'] < 0 || (int)$d['options']['start_of_week'] > 6) { dtp_fail('Некорректные числовые options'); }
    foreach (array('site_icon','site_logo') as $key) {
        if (!isset($d[$key]['file']) || !is_string($d[$key]['file'])) { dtp_fail('Некорректный ' . $key); }
    }
    foreach ($d['categories'] as $cat) {
        if (empty($cat['name']) || empty($cat['slug'])) { dtp_fail('Категория без имени/slug'); }
    }
    foreach ($d['menus'] as $location => $menu) {
        if (!is_string($location) || !isset($menu['items']) || !is_array($menu['items'])) { dtp_fail('Некорректное меню'); }
        foreach ($menu['items'] as $item) {
            if (!isset($item['title']) || empty($item['page'])) { dtp_fail('Пункт меню без title/page: ' . $location); }
        }
    }
    foreach (array('pages','drafts','posts','persons','sponsors','media') as $key) {
        if (!isset($d['counts'][$key]) || !is_int($d['counts'][$key]) || $d['counts'][$key] < 0) { dtp_fail('Некорректный counts.' . $key); }
    }
    if (count($items) !== $d['counts']['media']) { dtp_fail('counts.media не совпадает с attachments.json'); }
    $stock = dtp_stock($allowed, $replace);
    $stock_ids = array_map('intval', array_column($stock, 'ID'));
    foreach ($items as $item) {
        $id = (int)$item['ID'];
        if (!isset($item['post'], $item['meta']) || !is_array($item['post']) || !is_array($item['meta'])) { dtp_fail('Некорректное вложение ID=' . $id); }
        if (get_post($id) && !in_array($id, $stock_ids, true)) { dtp_fail('Коллизия ID вложения: ' . $id); }
    }
    $admins = get_users(array('role'=>'administrator', 'orderby'=>'ID', 'order'=>'ASC', 'number'=>1));
    if (!$admins) { dtp_fail('Нет администратора WordPress'); }
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    $installed = get_plugins();
    $importer = 'wordpress-importer/wordpress-importer.php';
    $neve = '';
    foreach ($d['themes'] as $theme) { if (($theme['name'] ?? '') === 'neve') { $neve = $theme['version']; } }
    if (!$neve || !preg_match('/^[0-9][0-9A-Za-z.+_-]*$/D', $neve)) { dtp_fail('Нет корректной версии neve'); }
    $plugins = array();
    foreach ($d['plugins'] as $plugin) {
        if (!preg_match('/^[a-z0-9][a-z0-9-]*$/D', $plugin['name'] ?? '') || !preg_match('/^[0-9][0-9A-Za-z.+_-]*$/D', $plugin['version'] ?? '')) { dtp_fail('Некорректное имя/версия плагина'); }
        if ($plugin['name'] !== 'wordpress-importer') { $plugins[] = $plugin; }
    }
    $upload = wp_upload_dir(null, false);
    if ($upload['error']) { dtp_fail($upload['error']); }
    if ($mode === 'prepare') {
        dtp_clear_content($stock, $replace);
        WP_CLI::success('Цель подготовлена');
    } else {
        echo 'DTPSTAT_JSON:' . wp_json_encode(array(
            'home'=>get_option('home'), 'siteurl'=>get_option('siteurl'),
            'content'=>WP_CONTENT_DIR, 'themes'=>get_theme_root(), 'uploads'=>$upload['basedir'],
            'plugins_dir'=>WP_PLUGIN_DIR, 'admin_id'=>(int)$admins[0]->ID,
            'stock'=>$stock, 'neve_version'=>$neve, 'plugins'=>$plugins,
            'importer_installed'=>isset($installed[$importer]), 'importer_active'=>is_plugin_active($importer)
        ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    }
    return;
}
if ($mode === 'restore') {
    // Проверяем все ID прежде, чем создавать первую запись.
    foreach ($items as $item) { if (get_post((int)$item['ID'])) { dtp_fail('Занят ID=' . $item['ID']); } }
    $admins = get_users(array('role'=>'administrator','orderby'=>'ID','number'=>1));
    if (!$admins) { dtp_fail('Нет администратора'); }
    foreach ($items as $index => $item) {
        $post = $item['post'];
        unset($post['ID'], $post['post_parent']);
        $post['import_id'] = (int)$item['ID'];
        $post['post_author'] = (int)$admins[0]->ID;
        // wp_insert_post снимает слеши; экранируем только на границе WP API.
        $id = dtp_ok(wp_insert_attachment(wp_slash($post), false, 0, true), 'Создание вложения');
        if ((int)$id !== (int)$item['ID']) { dtp_fail('Не сохранён исходный ID=' . $item['ID'] . ', получен ' . $id); }
        foreach ($item['meta'] as $key => $value) {
            update_post_meta($id, $key, wp_slash($value));
            $got = get_post_meta($id, $key, true);
            $expected = is_array($value) ? $value : (string)$value;
            if ($got != $expected) { dtp_fail('Не записано meta ' . $key . ' для ID=' . $id); }
        }
        clean_attachment_cache($id);
        WP_CLI::log('Медиа ' . ($index + 1) . '/' . count($items) . ': ID=' . $id . ' ' . $item['meta']['_wp_attached_file']);
    }
    WP_CLI::success('Восстановлены все вложения: ' . count($items));
    return;
}
if ($mode === 'configure') {
    $options = $d['options'];
    $options['blogname'] = $d['blogname'];
    $options['blogdescription'] = $d['blogdescription'];
    $options['show_on_front'] = $d['pages']['front'] === '' ? 'posts' : 'page';
    $options['page_on_front'] = dtp_page($d['pages']['front']);
    $options['page_for_posts'] = dtp_page($d['pages']['posts']);
    $options['wp_page_for_privacy_policy'] = dtp_page($d['pages']['privacy']);
    $options['site_icon'] = dtp_attachment($d['site_icon']['file']);
    $options['site_logo'] = dtp_attachment($d['site_logo']['file']);
    foreach ($options as $key => $value) {
        update_option($key, $value);
        if (get_option($key) != $value) { dtp_fail('Не сохранена настройка: ' . $key); }
        WP_CLI::log('Настройка: ' . $key);
    }
    foreach ($d['categories'] as $cat) {
        $term = get_term_by('slug', $cat['slug'], 'category');
        if (!$term) { dtp_ok(wp_insert_term($cat['name'], 'category', array('slug'=>$cat['slug'])), 'Категория ' . $cat['slug']); }
    }
    $registered = get_registered_nav_menus();
    $locations = get_nav_menu_locations();
    foreach ($d['menus'] as $location => $menu) {
        if (!array_key_exists($location, $registered)) { dtp_fail('Тема не зарегистрировала location: ' . $location); }
        $name = $location === 'primary' ? 'Главное меню' : ($location === 'footer' ? 'Меню футера' : 'DTP ' . $location);
        $existing = wp_get_nav_menu_object($name);
        if ($existing) { dtp_ok(wp_delete_nav_menu($existing->term_id), 'Удаление меню'); }
        $mid = dtp_ok(wp_create_nav_menu($name), 'Создание меню');
        foreach ($menu['items'] as $position => $item) {
            dtp_ok(wp_update_nav_menu_item($mid, 0, array(
                'menu-item-object-id'=>dtp_page($item['page']), 'menu-item-object'=>'page',
                'menu-item-type'=>'post_type', 'menu-item-title'=>$item['title'],
                'menu-item-status'=>'publish', 'menu-item-position'=>$position + 1
            )), 'Пункт меню');
        }
        $locations[$location] = $mid;
        WP_CLI::log('Меню: ' . $name . ' -> ' . $location);
    }
    set_theme_mod('nav_menu_locations', $locations);
    WP_CLI::success('Настройки и меню восстановлены');
    return;
}
if ($mode === 'verify') {
    $url = $args[3] ?? '';
    $checks = array('pages'=>array('page','publish'), 'drafts'=>array('page','draft'),
        'posts'=>array('post','publish'), 'persons'=>array('dtp_person',null),
        'sponsors'=>array('dtp_sponsor',null), 'media'=>array('attachment',null));
    foreach ($checks as $key => $spec) {
        $sql = $wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type=%s", $spec[0]);
        $sql .= $spec[1] === null ? " AND post_status NOT IN ('trash','auto-draft')" : $wpdb->prepare(' AND post_status=%s', $spec[1]);
        $got = (int)$wpdb->get_var($sql);
        if ($wpdb->last_error || $got !== $d['counts'][$key]) { dtp_fail('Счётчик ' . $key . ': ' . $got . ', ожидалось ' . $d['counts'][$key]); }
        WP_CLI::log('OK: ' . $key . '=' . $got);
    }
    foreach ($items as $item) {
        $post = get_post((int)$item['ID']);
        if (!$post || $post->post_type !== 'attachment') { dtp_fail('Отсутствует вложение ID=' . $item['ID']); }
        foreach ($item['meta'] as $key => $value) {
            $expected = dtp_replace($value, rtrim($d['source_url'], '/'), $url);
            if (!is_array($expected)) { $expected = (string)$expected; }
            if (get_post_meta($post->ID, $key, true) != $expected) { dtp_fail('Meta отличается: ID=' . $post->ID . ', ' . $key); }
        }
        $file = get_attached_file($post->ID);
        if (!$file || !is_readable($file)) { dtp_fail('Недоступен файл ID=' . $post->ID); }
    }
    foreach ($d['options'] as $key => $value) {
        if (get_option($key) != $value) { dtp_fail('Отличается настройка: ' . $key); }
    }
    foreach (array('home'=>$url,'siteurl'=>$url,'blogname'=>$d['blogname'],'blogdescription'=>$d['blogdescription'],
        'page_on_front'=>dtp_page($d['pages']['front']), 'page_for_posts'=>dtp_page($d['pages']['posts']),
        'wp_page_for_privacy_policy'=>dtp_page($d['pages']['privacy']),
        'site_icon'=>dtp_attachment($d['site_icon']['file']), 'site_logo'=>dtp_attachment($d['site_logo']['file'])) as $key=>$expected) {
        if (get_option($key) != $expected) { dtp_fail('Отличается настройка: ' . $key); }
    }
    foreach ($d['categories'] as $cat) {
        if (!get_term_by('slug', $cat['slug'], 'category')) { dtp_fail('Отсутствует категория: ' . $cat['slug']); }
    }
    $locations = get_nav_menu_locations();
    foreach ($d['menus'] as $location => $menu) {
        if (empty($locations[$location])) { dtp_fail('Не назначено меню: ' . $location); }
        $actual = wp_get_nav_menu_items($locations[$location]);
        if (!is_array($actual) || count($actual) !== count($menu['items'])) { dtp_fail('Число пунктов меню: ' . $location); }
        foreach (array_values($actual) as $i => $item) {
            if ((int)$item->object_id !== dtp_page($menu['items'][$i]['page']) || $item->title !== $menu['items'][$i]['title']) { dtp_fail('Состав/порядок меню: ' . $location); }
        }
    }
    if (get_stylesheet() !== 'dtpstat-child') { dtp_fail('Не активирована dtpstat-child'); }
    if (rtrim($d['source_url'], '/') !== $url) {
        $pattern = '%' . $wpdb->esc_like(rtrim($d['source_url'], '/')) . '%';
        $remaining = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_content LIKE %s OR guid LIKE %s", $pattern, $pattern));
        if ($wpdb->last_error || (int)$remaining > 0) { dtp_fail('В записях остались исходные URL: ' . $remaining); }
    }
    WP_CLI::success('Проверены счётчики, все meta вложений, настройки, категории и состав меню. HTTP-проверки не выполнялись.');
    return;
}
dtp_fail('Неизвестный режим: ' . $mode);
