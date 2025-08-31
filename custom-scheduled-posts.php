<?php
/*
Plugin Name: カスタム予約投稿
Description: 下書きの予約投稿時間と件数、過去の投稿記事の再投稿日数を設定し、パーマリンクやタイトルが重複している記事を検出および削除するプラグイン。
Version: 1.4.0
Author: Watayoshi
Mail: dti.watayoshi@gmail.com
*/

// 設定画面を追加
add_action('admin_menu', 'csp_add_admin_menu');
add_action('admin_init', 'csp_settings_init');
add_action('admin_init', 'csp_category_settings_init');

// 設定保存時のnonceチェック
add_action('admin_post_csp_save_settings', 'csp_save_settings');
add_action('admin_post_csp_save_category_settings', 'csp_save_category_settings');

// カスタムログ出力関数
if (!function_exists('csp_log')) {
    function csp_log($message, $level = 'INFO') {
        $log_message = sprintf(
            '[%s] [%s] %s',
            date('Y-m-d H:i:s'),
            $level,
            $message
        );
        error_log($log_message);
    }
}

// 例外ハンドリング用のカスタム例外クラス
if (!class_exists('CSP_Exception')) {
    class CSP_Exception extends Exception {}
}

// メインメニューのサブメニューとして設定ページを追加
function csp_add_admin_menu() {
    add_menu_page(
        'カスタム予約投稿', 
        'カスタム予約投稿', 
        'manage_options', 
        'custom_scheduled_posts', 
        'csp_options_page',
        'dashicons-calendar-alt',
        25
    );
    
    add_submenu_page(
        'custom_scheduled_posts',
        '基本設定',
        '基本設定',
        'manage_options',
        'custom_scheduled_posts',
        'csp_options_page'
    );
    
    // カテゴリー別設定のサブメニューページを追加
    add_submenu_page(
        'custom_scheduled_posts',
        'カテゴリー別設定',
        'カテゴリー別設定',
        'manage_options',
        'csp_category_settings',
        'csp_category_settings_page'
    );
    
    // 内部リンク分析のサブメニューページを追加（ダミー）
    add_submenu_page(
        'custom_scheduled_posts',
        '内部リンク分析',
        '内部リンク分析',
        'manage_options',
        'csp_link_analysis',
        'csp_link_analysis_page'
    );
}

// カテゴリー別設定ページ
function csp_category_settings_page() {
    // カテゴリー一覧を取得
    $categories = get_categories(array('hide_empty' => false));
    $category_options = get_option('csp_category_options', array());
    
    echo '<div class="wrap">';
    echo '<h1>カテゴリー別設定</h1>';
    echo '<form action="' . admin_url('admin-post.php') . '" method="post">';
    echo '<input type="hidden" name="action" value="csp_save_category_settings">';
    wp_nonce_field('csp_save_category_settings', 'csp_nonce');
    
    echo '<table class="form-table">';
    echo '<thead>';
    echo '<tr>';
    echo '<th>カテゴリー名</th>';
    echo '<th>更新期間（日数）</th>';
    echo '</tr>';
    echo '</thead>';
    echo '<tbody>';
    
    foreach ($categories as $category) {
        $repost_days = isset($category_options[$category->term_id]) ? 
            intval($category_options[$category->term_id]) : 7;
        
        echo '<tr>';
        echo '<td>' . esc_html($category->name) . '</td>';
        echo '<td>';
        echo '<input type="number" name="csp_category_options[' . $category->term_id . ']" value="' . $repost_days . '" min="1">';
        echo '</td>';
        echo '</tr>';
    }
    
    echo '</tbody>';
    echo '</table>';
    
    submit_button();
    echo '</form>';
    echo '</div>';
}

// カテゴリー別設定の初期化
function csp_category_settings_init() {
    register_setting('csp_category_settings', 'csp_category_options');
}

// カテゴリー別設定保存処理
function csp_save_category_settings() {
    try {
        // nonceの検証
        if (!isset($_POST['csp_nonce']) || !wp_verify_nonce($_POST['csp_nonce'], 'csp_save_category_settings')) {
            throw new CSP_Exception('セキュリティエラー：不正なアクセスが検出されました。');
        }
        
        // 権限の確認
        if (!current_user_can('manage_options')) {
            throw new CSP_Exception('権限がありません。');
        }
        
        // 入力値のサニタイズとバリデーション
        $category_options = array();
        if (isset($_POST['csp_category_options']) && is_array($_POST['csp_category_options'])) {
            foreach ($_POST['csp_category_options'] as $category_id => $repost_days) {
                $category_options[intval($category_id)] = max(1, intval($repost_days));
            }
        }
        
        update_option('csp_category_options', $category_options);
        
        csp_log('カテゴリー別設定が正常に保存されました。');
        
        // 設定保存後のリダイレクト
        wp_redirect(add_query_arg('settings-updated', 'true', admin_url('admin.php?page=csp_category_settings')));
        exit;
    } catch (CSP_Exception $e) {
        csp_log('カテゴリー別設定保存エラー: ' . $e->getMessage(), 'ERROR');
        wp_die(esc_html($e->getMessage()));
    } catch (Exception $e) {
        csp_log('予期しないエラー: ' . $e->getMessage(), 'ERROR');
        wp_die('予期しないエラーが発生しました。詳細はエラーログを確認してください。');
    }
}

// ショートコード[custom_posts_link]の実装
add_shortcode('custom_posts_link', 'csp_custom_posts_link_shortcode');

function csp_custom_posts_link_shortcode($atts) {
    // ショートコードの属性を取得
    $atts = shortcode_atts(array(
        'post_id' => get_the_ID(),
        'display' => 'graph', // graph または list
    ), $atts);
    
    $post_id = intval($atts['post_id']);
    $display_type = sanitize_text_field($atts['display']);
    
    // 投稿が存在するか確認
    $post = get_post($post_id);
    if (!$post) {
        return '<p>指定された投稿が存在しません。</p>';
    }
    
    // デバッグ用ログ出力
    csp_log('ショートコード呼び出し: post_id=' . $post_id . ', display_type=' . $display_type, 'DEBUG');
    csp_log('投稿内容: ' . $post->post_content, 'DEBUG');
    
    // 内部リンクを抽出
    $internal_links = csp_extract_internal_links($post->post_content);
    
    // デバッグ用ログ出力
    csp_log('抽出された内部リンク: ' . print_r($internal_links, true), 'DEBUG');
    
    // 表示タイプに応じて出力を変更
    if ($display_type === 'list') {
        return csp_render_internal_links_list($internal_links);
    } else {
        return csp_render_internal_links_graph($internal_links, $post_id);
    }
}

// コンテンツから内部リンクを抽出する関数（アンカーテキスト対応版）
function csp_extract_internal_links($content) {
    $links = array();
    
    // href属性とアンカーテキストを含むaタグを抽出
    preg_match_all('/<a\s+(?:[^>]*?\s+)?href="(.*?)".*?>(.*?)<\/a>/i', $content, $matches, PREG_SET_ORDER);
    
    if (!empty($matches)) {
        foreach ($matches as $match) {
            $url = $match[1];
            $anchor_text = strip_tags($match[2]);
            
            // WordPressサイト内のURLか確認
            if (strpos($url, home_url()) === 0) {
                // URLから投稿IDを取得
                $linked_post_id = url_to_postid($url);
                
                // url_to_postidが失敗した場合、手動で解析を試みる
                if (!$linked_post_id) {
                    // URLのパス部分を取得
                    $url_path = parse_url($url, PHP_URL_PATH);
                    if ($url_path) {
                        // パーマリンクから投稿を取得
                        $linked_post = get_page_by_path($url_path, OBJECT, ['post', 'page']);
                        if ($linked_post) {
                            $linked_post_id = $linked_post->ID;
                        }
                    }
                }
                
                if ($linked_post_id) {
                    $links[] = array(
                        'url' => $url,
                        'post_id' => $linked_post_id,
                        'title' => get_the_title($linked_post_id),
                        'anchor' => $anchor_text,
                    );
                }
            }
        }
    }
    
    // デバッグ用ログ出力
    csp_log('内部リンク抽出結果: ' . print_r($links, true), 'DEBUG');
    
    return $links;
}

// 内部リンクをリスト形式で表示する関数
function csp_render_internal_links_list($links) {
    if (empty($links)) {
        return '<p>内部リンクが見つかりませんでした。</p>';
    }
    
    $output = '<ul class="csp-internal-links-list">';
    foreach ($links as $link) {
        $output .= '<li><a href="' . esc_url($link['url']) . '">' . esc_html($link['title']) . '</a></li>';
    }
    $output .= '</ul>';
    
    return $output;
}

// グラフィカル表示の拡張機能
function csp_render_internal_links_graph($links, $current_post_id) {
    // デバッグ用ログ出力
    csp_log('グラフ描画関数呼び出し: リンク数=' . count($links) . ', 現在の投稿ID=' . $current_post_id, 'DEBUG');
    
    if (empty($links)) {
        return '<p>内部リンクが見つかりませんでした。</p>';
    }
    
    // 設定オプションを取得
    $display_options = get_option('csp_link_display_options', array(
        'show_categories' => true,
        'show_tags' => true,
        'show_posts' => true,
        'show_pages' => true,
    ));
    
    // 表示対象のフィルタリング
    $filtered_links = array();
    foreach ($links as $link) {
        $linked_post = get_post($link['post_id']);
        if (!$linked_post) {
            csp_log('リンク先の投稿が存在しません: post_id=' . $link['post_id'], 'DEBUG');
            continue;
        }
        
        // 投稿ページの表示設定を確認
        if ($linked_post->post_type === 'post' && !$display_options['show_posts']) {
            continue;
        }
        
        // 固定ページの表示設定を確認
        if ($linked_post->post_type === 'page' && !$display_options['show_pages']) {
            continue;
        }
        
        // カテゴリーの表示設定を確認
        if ($display_options['show_categories']) {
            $categories = get_the_category($link['post_id']);
            if (!empty($categories)) {
                $link['categories'] = wp_list_pluck($categories, 'name');
            }
        }
        
        // タグの表示設定を確認
        if ($display_options['show_tags']) {
            $tags = get_the_tags($link['post_id']);
            if (!empty($tags)) {
                $link['tags'] = wp_list_pluck($tags, 'name');
            }
        }
        
        $filtered_links[] = $link;
    }
    
    // フィルタリング後のリンク数をログ出力
    csp_log('フィルタリング後のリンク数: ' . count($filtered_links), 'DEBUG');
    
    // D3.jsを読み込み
    wp_enqueue_script('d3', 'https://d3js.org/d3.v7.min.js', array(), '7.0.0', true);
    
    // グラフ描画用のスクリプトを追加
    ob_start();
    ?>
    <div id="csp-link-graph" style="width: 100%; height: 500px; border: 1px solid #ccc;"></div>
    <div id="csp-graph-controls" style="margin: 10px 0;">
        <label><input type="checkbox" id="toggle-categories" checked> カテゴリー表示</label>
        <label><input type="checkbox" id="toggle-tags" checked> タグ表示</label>
        <label><input type="checkbox" id="toggle-anchors" checked> アンカーテキスト表示</label>
    </div>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        console.log('D3.jsグラフ描画開始');
        
        // グラフデータを準備
        const nodes = [
            { id: <?php echo intval($current_post_id); ?>, title: "<?php echo esc_js(get_the_title($current_post_id)); ?>", type: "current" }
        ];
        
        const links = [];
        
        <?php foreach ($filtered_links as $link): ?>
        nodes.push({ 
            id: <?php echo intval($link['post_id']); ?>, 
            title: "<?php echo esc_js($link['title']); ?>",
            type: "<?php echo get_post_type($link['post_id']); ?>",
            categories: <?php echo json_encode(isset($link['categories']) ? $link['categories'] : array()); ?>,
            tags: <?php echo json_encode(isset($link['tags']) ? $link['tags'] : array()); ?>
        });
        links.push({ 
            source: <?php echo intval($current_post_id); ?>, 
            target: <?php echo intval($link['post_id']); ?>,
            anchor: "<?php echo esc_js($link['anchor'] ?? ''); ?>"
        });
        <?php endforeach; ?>
        
        console.log('ノード数:', nodes.length);
        console.log('リンク数:', links.length);
        
        // D3.jsでグラフを描画
        const svg = d3.select("#csp-link-graph")
            .append("svg")
            .attr("width", "100%")
            .attr("height", "100%");
            
        const width = parseInt(d3.select("#csp-link-graph").style("width"));
        const height = parseInt(d3.select("#csp-link-graph").style("height"));
        
        // グラフのシミュレーションを設定
        const simulation = d3.forceSimulation(nodes)
            .force("link", d3.forceLink(links).id(d => d.id).distance(100))
            .force("charge", d3.forceManyBody().strength(-300))
            .force("center", d3.forceCenter(width / 2, height / 2));
        
        // リンクを描画
        const link = svg.append("g")
            .attr("stroke", "#999")
            .attr("stroke-opacity", 0.6)
            .selectAll("line")
            .data(links)
            .join("line")
            .attr("stroke-width", 2);
        
        // リンクのアンカーテキストを描画
        const linkText = svg.append("g")
            .attr("font-family", "sans-serif")
            .attr("font-size", 8)
            .selectAll("text")
            .data(links)
            .join("text")
            .text(d => d.anchor)
            .attr("dx", 10)
            .attr("dy", "0.31em")
            .attr("fill", "#666")
            .style("display", "none"); // 初期状態では非表示
        
        // ノードを描画（カテゴリーごとに色分け）
        const node = svg.append("g")
            .attr("stroke", "#fff")
            .attr("stroke-width", 1.5)
            .selectAll("circle")
            .data(nodes)
            .join("circle")
            .attr("r", d => d.type === "current" ? 15 : 10)
            .attr("fill", d => {
                if (d.type === "current") return "#ff6b6b"; // 現在の投稿は赤色
                if (d.type === "page") return "#4ecdc4"; // 固定ページは青緑色
                // カテゴリーごとの色分け
                if (d.categories && d.categories.length > 0) {
                    const category = d.categories[0]; // 最初のカテゴリーを使用
                    // カテゴリー名のハッシュ値に基づいて色を生成
                    const hash = Array.from(category).reduce((acc, char) => {
                        return acc + char.charCodeAt(0);
                    }, 0);
                    const hue = hash % 360;
                    return `hsl(${hue}, 70%, 40%)`;
                }
                return "#69b3a2"; // デフォルトの色
            })
            .call(drag(simulation));
        
        // ノードのラベルを描画
        const label = svg.append("g")
            .attr("font-family", "sans-serif")
            .attr("font-size", 10)
            .selectAll("text")
            .data(nodes)
            .join("text")
            .text(d => d.title)
            .attr("x", 12)
            .attr("y", "0.31em");
        
        // カテゴリーラベルを描画
        const categoryLabel = svg.append("g")
            .attr("font-family", "sans-serif")
            .attr("font-size", 8)
            .selectAll("text")
            .data(nodes.filter(d => d.categories && d.categories.length > 0))
            .join("text")
            .text(d => d.categories.join(", "))
            .attr("x", 12)
            .attr("y", "1.31em")
            .attr("fill", "#666");
        
        // タグラベルを描画
        const tagLabel = svg.append("g")
            .attr("font-family", "sans-serif")
            .attr("font-size", 8)
            .selectAll("text")
            .data(nodes.filter(d => d.tags && d.tags.length > 0))
            .join("text")
            .text(d => d.tags.join(", "))
            .attr("x", 12)
            .attr("y", "2.31em")
            .attr("fill", "#999");
        
        // シミュレーションのtickイベントを設定
        simulation.on("tick", () => {
            link
                .attr("x1", d => d.source.x)
                .attr("y1", d => d.source.y)
                .attr("x2", d => d.target.x)
                .attr("y2", d => d.target.y);
            
            linkText
                .attr("x", d => (d.source.x + d.target.x) / 2)
                .attr("y", d => (d.source.y + d.target.y) / 2);
            
            node
                .attr("cx", d => d.x)
                .attr("cy", d => d.y);
                
            label
                .attr("x", d => d.x + 12)
                .attr("y", d => d.y + 3);
                
            categoryLabel
                .attr("x", d => d.x + 12)
                .attr("y", d => d.y + 15);
                
            tagLabel
                .attr("x", d => d.x + 12)
                .attr("y", d => d.y + 27);
        });
        
        // ドラッグ機能を設定
        function drag(simulation) {
            function dragstarted(event, d) {
                if (!event.active) simulation.alphaTarget(0.3).restart();
                d.fx = d.x;
                d.fy = d.y;
            }
            
            function dragged(event, d) {
                d.fx = event.x;
                d.fy = event.y;
            }
            
            function dragended(event, d) {
                if (!event.active) simulation.alphaTarget(0);
                d.fx = null;
                d.fy = null;
            }
            
            return d3.drag()
                .on("start", dragstarted)
                .on("drag", dragged)
                .on("end", dragended);
        }
        
        // 表示/非表示の切り替え機能
        document.getElementById('toggle-categories').addEventListener('change', function() {
            if (this.checked) {
                categoryLabel.style("display", null);
            } else {
                categoryLabel.style("display", "none");
            }
        });
        
        document.getElementById('toggle-tags').addEventListener('change', function() {
            if (this.checked) {
                tagLabel.style("display", null);
            } else {
                tagLabel.style("display", "none");
            }
        });
        
        document.getElementById('toggle-anchors').addEventListener('change', function() {
            if (this.checked) {
                linkText.style("display", null);
            } else {
                linkText.style("display", "none");
            }
        });
    });
    </script>
    <style>
    .csp-internal-links-list {
        list-style-type: disc;
        padding-left: 20px;
    }
    .csp-internal-links-list li {
        margin-bottom: 5px;
    }
    #csp-graph-controls label {
        margin-right: 15px;
    }
    </style>
    <?php
    return ob_get_clean();
}

// 内部リンク分析ページ
function csp_link_analysis_page() {
    echo '<div class="wrap">';
    echo '<h1>内部リンク分析</h1>';
    
    // ショートコードをデモとして表示
    echo '<h2>ショートコードの使用例</h2>';
    echo '<p>以下のショートコードを投稿や固定ページに追加することで、内部リンク構造をグラフィカルに表示できます：</p>';
    echo '<pre>[custom_posts_link]</pre>';
    echo '<p>リスト形式で表示する場合は以下のように指定します：</p>';
    echo '<pre>[custom_posts_link display="list"]</pre>';
    
    // 実際の表示例
    echo '<h2>表示例</h2>';
    echo do_shortcode('[custom_posts_link]');
    
    echo '</div>';
}

function csp_settings_init() {
    register_setting('csp_settings', 'csp_options');
    
    // nonceフィールドの追加
    add_action('csp_settings_form_top', 'csp_nonce_field');
    
    add_settings_section(
        'csp_settings_section',
        __('基本設定', 'wordpress'),
        'csp_settings_section_callback',
        'csp_settings'
    );

    add_settings_field(
        'csp_interval',
        __('下書きの予約投稿間隔（時間）', 'wordpress'),
        'csp_interval_render',
        'csp_settings',
        'csp_settings_section'
    );

    add_settings_field(
        'csp_drafts_per_interval',
        __('各間隔ごとの下書き投稿件数', 'wordpress'),
        'csp_drafts_per_interval_render',
        'csp_settings',
        'csp_settings_section'
    );

    add_settings_field(
        'csp_repost_days',
        __('再投稿する日数（現在日時より経過した日数）', 'wordpress'),
        'csp_repost_days_render',
        'csp_settings',
        'csp_settings_section'
    );

    add_settings_field(
        'csp_exclude_post_ids',
        __('予約投稿を除外する記事ID（カンマ区切り）', 'wordpress'),
        'csp_exclude_post_ids_render',
        'csp_settings',
        'csp_settings_section'
    );
}

// nonceフィールドの表示
function csp_nonce_field() {
    wp_nonce_field('csp_save_settings', 'csp_nonce');
}

// 設定保存処理
function csp_save_settings() {
    try {
        // nonceの検証
        if (!isset($_POST['csp_nonce']) || !wp_verify_nonce($_POST['csp_nonce'], 'csp_save_settings')) {
            throw new CSP_Exception('セキュリティエラー：不正なアクセスが検出されました。');
        }
        
        // 権限の確認
        if (!current_user_can('manage_options')) {
            throw new CSP_Exception('権限がありません。');
        }
        
        // 入力値のサニタイズとバリデーション
        $options = get_option('csp_options');
        $options['csp_interval'] = isset($_POST['csp_options']['csp_interval']) ? 
            max(1, intval($_POST['csp_options']['csp_interval'])) : 1;
        $options['csp_drafts_per_interval'] = isset($_POST['csp_options']['csp_drafts_per_interval']) ? 
            max(1, intval($_POST['csp_options']['csp_drafts_per_interval'])) : 1;
        $options['csp_repost_days'] = isset($_POST['csp_options']['csp_repost_days']) ? 
            max(1, intval($_POST['csp_options']['csp_repost_days'])) : 7;
        $options['csp_exclude_post_ids'] = isset($_POST['csp_options']['csp_exclude_post_ids']) ? 
            sanitize_text_field($_POST['csp_options']['csp_exclude_post_ids']) : '';
        
        update_option('csp_options', $options);
        
        csp_log('設定が正常に保存されました。');
        
        // 設定保存後のリダイレクト
        wp_redirect(add_query_arg('settings-updated', 'true', $_POST['_wp_http_referer']));
        exit;
    } catch (CSP_Exception $e) {
        csp_log('設定保存エラー: ' . $e->getMessage(), 'ERROR');
        wp_die(esc_html($e->getMessage()));
    } catch (Exception $e) {
        csp_log('予期しないエラー: ' . $e->getMessage(), 'ERROR');
        wp_die('予期しないエラーが発生しました。詳細はエラーログを確認してください。');
    }
}

function csp_interval_render() {
    $options = get_option('csp_options');
    ?>
    <input type='number' name='csp_options[csp_interval]' value='<?php echo esc_attr($options['csp_interval']); ?>' min='1'>
    <p class="description">指定された時間ごとに下書きを予約投稿します。</p>
    <?php
}

function csp_drafts_per_interval_render() {
    $options = get_option('csp_options');
    ?>
    <input type='number' name='csp_options[csp_drafts_per_interval]' value='<?php echo esc_attr($options['csp_drafts_per_interval']); ?>' min='1'>
    <p class="description">各間隔ごとに予約投稿する下書きの件数を指定します。</p>
    <?php
}

function csp_repost_days_render() {
    $options = get_option('csp_options');
    ?>
    <input type='number' name='csp_options[csp_repost_days]' value='<?php echo esc_attr($options['csp_repost_days']); ?>' min='1'>
    <p class="description">指定された日数より古い投稿を再投稿します。</p>
    <?php
}

function csp_exclude_post_ids_render() {
    $options = get_option('csp_options');
    ?>
    <input type='text' name='csp_options[csp_exclude_post_ids]' value='<?php echo esc_attr($options['csp_exclude_post_ids']); ?>'>
    <p class="description">予約投稿から除外する記事IDをカンマで区切って入力します。</p>
    <?php
}

function csp_settings_section_callback() {
    echo __('カスタム予約投稿の基本設定を行います。以下のオプションを設定してください。', 'wordpress');
}

function csp_options_page() {
    ?>
    <div class="wrap">
        <h1>カスタム予約投稿</h1>
        <nav class="nav-tab-wrapper">
            <a href="?page=custom_scheduled_posts" class="nav-tab nav-tab-active">基本設定</a>
            <a href="?page=csp_category_settings" class="nav-tab">カテゴリー別設定</a>
            <a href="?page=csp_link_analysis" class="nav-tab">内部リンク分析</a>
        </nav>
        <form action='<?php echo admin_url('admin-post.php'); ?>' method='post'>
            <?php do_action('csp_settings_form_top'); ?>
            <input type="hidden" name="action" value="csp_save_settings">
            <h2>基本設定</h2>
            <?php
            settings_fields('csp_settings');
            do_settings_sections('csp_settings');
            submit_button();
            ?>
        </form>
    </div>
    <style>
        .nav-tab-wrapper {
            margin-bottom: 20px;
        }
    </style>
    <?php
}

// カテゴリーごとの更新期間を取得する関数
function csp_get_category_repost_days($category_id) {
    $category_options = get_option('csp_category_options', array());
    return isset($category_options[$category_id]) ? intval($category_options[$category_id]) : 7;
}

// カテゴリーごとの再投稿処理
function csp_repost_old_posts_by_category() {
    try {
        // カテゴリー一覧を取得
        $categories = get_categories(array('hide_empty' => false));
        
        foreach ($categories as $category) {
            $repost_days = csp_get_category_repost_days($category->term_id);
            
            // カテゴリーに属する古い投稿を取得
            $old_posts = get_posts(array(
                'post_status' => 'publish',
                'post_type' => 'post',
                'category' => $category->term_id,
                'date_query' => array(
                    array(
                        'column' => 'post_date_gmt',
                        'before' => $repost_days . ' days ago',
                    ),
                ),
                'posts_per_page' => -1,
            ));
            
            // 古い投稿を再投稿
            foreach ($old_posts as $post) {
                $post_data = array(
                    'ID' => $post->ID,
                    'post_date' => current_time('mysql'),
                    'post_date_gmt' => current_time('mysql', 1),
                );
                wp_update_post($post_data);
            }
            
            csp_log(sprintf('カテゴリー「%s」の%d件の古い投稿を再投稿しました。', $category->name, count($old_posts)));
        }
    } catch (Exception $e) {
        csp_log('カテゴリー別再投稿エラー: ' . $e->getMessage(), 'ERROR');
    }
}

// ダッシュボードに進捗状態と設定状態を表示
add_action('wp_dashboard_setup', 'csp_add_dashboard_widgets');

function csp_add_dashboard_widgets() {
    wp_add_dashboard_widget('csp_dashboard_widget', 'カスタム予約投稿の状態', 'csp_dashboard_widget_render');
}

function csp_dashboard_widget_render() {
    try {
        $options = get_option('csp_options');
        $interval = !empty($options['csp_interval']) ? intval($options['csp_interval']) : '未設定';
        $drafts_per_interval = !empty($options['csp_drafts_per_interval']) ? intval($options['csp_drafts_per_interval']) : '未設定';
        $repost_days = !empty($options['csp_repost_days']) ? intval($options['csp_repost_days']) : '未設定';
        $exclude_post_ids = !empty($options['csp_exclude_post_ids']) ? esc_html($options['csp_exclude_post_ids']) : 'なし';

        $next_publish_time = wp_next_scheduled('csp_hourly_event');
        $next_repost_check_time = wp_next_scheduled('csp_daily_event');
        $last_duplicate_check_time = get_option('csp_last_duplicate_check_time', '未実行');
        $last_deleted_count = csp_get_last_deleted_count();
        $total_published_count = get_option('csp_total_published_count', 0);
        $last_published_count = get_option('csp_last_published_count', 0);

        $wp_timezone = wp_timezone();
        $next_publish_time_local = $next_publish_time ? wp_date('Y-m-d H:i:s', $next_publish_time, $wp_timezone) : '未設定';
        $next_repost_check_time_local = $next_repost_check_time ? wp_date('Y-m-d H:i:s', $next_repost_check_time, $wp_timezone) : '未設定';
        $last_duplicate_check_time_local = $last_duplicate_check_time !== '未実行' ? wp_date('Y-m-d H:i:s', $last_duplicate_check_time, $wp_timezone) : '未実行';

        echo '<h3>#設定状態</h3>';
        echo '<p><strong>・下書きの予約投稿間隔（時間）:</strong> ' . esc_html($interval) . '</p>';
        echo '<p><strong>・各間隔ごとの下書き投稿件数:</strong> ' . esc_html($drafts_per_interval) . '</p>';
        echo '<p><strong>・予約投稿を除外する記事ID:</strong> ' . esc_html($exclude_post_ids) . '</p>';
        echo '<p><strong>・再投稿をする日数:</strong> ' . esc_html($repost_days) . '</p>';

        echo '<h3>#進捗状態</h3>';
        echo '<p><strong>・次の予約投稿時間:</strong> ' . esc_html($next_publish_time_local) . '</p>';
        echo '<p><strong>・次の再投稿チェック時間:</strong> ' . esc_html($next_repost_check_time_local) . '</p>';
        echo '<p><strong>・最後の重複チェック実行日時:</strong> ' . esc_html($last_duplicate_check_time_local) . '</p>';

        echo '<h3>#結果状態</h3>';
        echo '<p><strong>・前回の予約投稿件数:</strong> ' . esc_html($last_published_count) . '</p>';
        echo '<p><strong>・累計の予約投稿件数:</strong> ' . esc_html($total_published_count) . '</p>';

        // 進捗状態を表示
        $drafts = get_posts(array(
            'post_status' => 'draft',
            'post_type' => 'post',
            'posts_per_page' => -1,
        ));
        $old_posts = get_posts(array(
            'post_status' => 'publish',
            'post_type' => 'post',
            'date_query' => array(
                array(
                    'column' => 'post_date_gmt',
                    'before' => $repost_days . ' days ago',
                ),
            ),
            'posts_per_page' => -1,
        ));

        echo '<p><strong>・下書きの数:</strong> ' . count($drafts) . '</p>';
        echo '<p><strong>・再投稿が必要な投稿の数:</strong> ' . count($old_posts) . '</p>';

        echo '<p><strong>・重複チェックで削除された記事数:</strong> ' . esc_html($last_deleted_count) . '</p>';

        echo '<a href="' . admin_url('options-general.php?page=custom_scheduled_posts') . '" class="button button-primary">設定画面へ</a>';
        echo ' <a href="' . wp_nonce_url(admin_url('admin-post.php?action=csp_manual_update'), 'csp_manual_update') . '" class="button">更新</a>';
    } catch (Exception $e) {
        csp_log('ダッシュボードウィジェット表示エラー: ' . $e->getMessage(), 'ERROR');
        echo '<p>状態の取得中にエラーが発生しました。詳細はエラーログを確認してください。</p>';
    }
}

// 手動更新アクション
add_action('admin_post_csp_manual_update', 'csp_manual_update');
function csp_manual_update() {
    try {
        // nonceの検証
        if (!isset($_GET['nonce']) || !wp_verify_nonce($_GET['nonce'], 'csp_manual_update')) {
            throw new CSP_Exception('セキュリティエラー：不正なアクセスが検出されました。');
        }
        
        csp_publish_drafts();
        csp_repost_old_posts();
        csp_check_for_duplicates();
        
        csp_log('手動更新が正常に完了しました。');
        
        wp_redirect(admin_url('index.php'));
        exit;
    } catch (CSP_Exception $e) {
        csp_log('手動更新エラー: ' . $e->getMessage(), 'ERROR');
        wp_die(esc_html($e->getMessage()));
    } catch (Exception $e) {
        csp_log('予期しないエラー: ' . $e->getMessage(), 'ERROR');
        wp_die('予期しないエラーが発生しました。詳細はエラーログを確認してください。');
    }
}

// スケジュールイベントの設定とクリア
register_activation_hook(__FILE__, 'csp_schedule_events');
register_deactivation_hook(__FILE__, 'csp_clear_scheduled_events');

function csp_schedule_events() {
    try {
        if (!wp_next_scheduled('csp_hourly_event')) {
            wp_schedule_event(time(), 'hourly', 'csp_hourly_event');
        }
        if (!wp_next_scheduled('csp_daily_event')) {
            wp_schedule_event(time(), 'daily', 'csp_daily_event');
        }
        if (!wp_next_scheduled('csp_weekly_event')) {
            wp_schedule_event(time(), 'weekly', 'csp_weekly_event');
        }
        
        csp_log('スケジュールイベントが正常に設定されました。');
    } catch (Exception $e) {
        csp_log('スケジュールイベント設定エラー: ' . $e->getMessage(), 'ERROR');
    }
}

function csp_clear_scheduled_events() {
    try {
        wp_clear_scheduled_hook('csp_hourly_event');
        wp_clear_scheduled_hook('csp_daily_event');
        wp_clear_scheduled_hook('csp_weekly_event');
        
        csp_log('スケジュールイベントが正常にクリアされました。');
    } catch (Exception $e) {
        csp_log('スケジュールイベントクリアエラー: ' . $e->getMessage(), 'ERROR');
    }
}

// 記事タイトルとパーマリンクの重複チェックと削除
function csp_check_for_duplicates() {
    try {
        global $wpdb;

        // 重複した記事タイトルを取得（パフォーマンス改善版）
        $duplicate_titles = $wpdb->get_results($wpdb->prepare("
            SELECT post_title, COUNT(*) as count
            FROM $wpdb->posts
            WHERE post_type = %s AND post_status = %s
            GROUP BY post_title
            HAVING COUNT(*) > 1
        ", 'post', 'publish'), ARRAY_A);

        // 重複した記事パーマリンクを取得（パフォーマンス改善版）
        $duplicate_slugs = $wpdb->get_results($wpdb->prepare("
            SELECT post_name, COUNT(*) as count
            FROM $wpdb->posts
            WHERE post_type = %s AND post_status = %s
            GROUP BY post_name
            HAVING COUNT(*) > 1
        ", 'post', 'publish'), ARRAY_A);

        // 重複した記事を削除（バッチ処理対応版）
        $deleted_count = 0;
        $batch_size = 50; // バッチサイズを設定
        
        if (!empty($duplicate_titles)) {
            foreach ($duplicate_titles as $duplicate) {
                $title = $duplicate['post_title'];
                $posts = get_posts(array(
                    'post_type' => 'post',
                    'post_status' => 'publish',
                    'title' => $title,
                    'posts_per_page' => -1,
                ));
                
                // 最初の1件を残し、残りを削除
                array_shift($posts);
                
                // バッチ処理で削除
                foreach (array_chunk($posts, $batch_size) as $batch) {
                    foreach ($batch as $post) {
                        wp_delete_post($post->ID, true);
                        $deleted_count++;
                    }
                    // メモリ解放
                    wp_cache_flush();
                }
            }
        }

        if (!empty($duplicate_slugs)) {
            foreach ($duplicate_slugs as $duplicate) {
                $slug = $duplicate['post_name'];
                $posts = get_posts(array(
                    'post_type' => 'post',
                    'post_status' => 'publish',
                    'name' => $slug,
                    'posts_per_page' => -1,
                ));
                
                // 最初の1件を残し、残りを削除
                array_shift($posts);
                
                // バッチ処理で削除
                foreach (array_chunk($posts, $batch_size) as $batch) {
                    foreach ($batch as $post) {
                        wp_delete_post($post->ID, true);
                        $deleted_count++;
                    }
                    // メモリ解放
                    wp_cache_flush();
                }
            }
        }

        // 削除した記事数を保存
        update_option('csp_last_deleted_count', $deleted_count);
        update_option('csp_last_duplicate_check_time', current_time('timestamp'));
        
        csp_log(sprintf('重複チェックを実行し、%d件の記事を削除しました。', $deleted_count));

        return $deleted_count;
    } catch (Exception $e) {
        csp_log('重複チェックエラー: ' . $e->getMessage(), 'ERROR');
        return 0;
    }
}

// 大量の下書きを処理するためのバッチ処理対応版関数
function csp_publish_drafts() {
    try {
        $options = get_option('csp_options');
        $drafts_per_interval = !empty($options['csp_drafts_per_interval']) ? intval($options['csp_drafts_per_interval']) : 1;
        $exclude_post_ids = !empty($options['csp_exclude_post_ids']) ? explode(',', $options['csp_exclude_post_ids']) : array();
        
        // 除外IDのサニタイズ
        $exclude_post_ids = array_map('intval', $exclude_post_ids);
        
        // バッチサイズを設定
        $batch_size = min($drafts_per_interval, 50);
        $published_count = 0;

        // バッチ処理で下書きを公開
        while ($published_count < $drafts_per_interval) {
            $remaining_count = $drafts_per_interval - $published_count;
            $current_batch_size = min($batch_size, $remaining_count);
            
            $drafts = get_posts(array(
                'post_status' => 'draft',
                'post_type' => 'post',
                'posts_per_page' => $current_batch_size,
                'exclude' => $exclude_post_ids,
            ));
            
            // これ以上下書きがない場合は終了
            if (empty($drafts)) {
                break;
            }
            
            foreach ($drafts as $draft) {
                wp_publish_post($draft->ID);
                $published_count++;
            }
            
            // メモリ解放
            wp_cache_flush();
        }

        update_option('csp_last_published_count', $published_count);
        $total_published_count = get_option('csp_total_published_count', 0) + $published_count;
        update_option('csp_total_published_count', $total_published_count);
        
        csp_log(sprintf('下書きを%d件予約投稿しました。', $published_count));
    } catch (Exception $e) {
        csp_log('下書き予約投稿エラー: ' . $e->getMessage(), 'ERROR');
    }
}

// 大量の古い投稿を処理するためのバッチ処理対応版関数
function csp_repost_old_posts() {
    try {
        $options = get_option('csp_options');
        $repost_days = !empty($options['csp_repost_days']) ? intval($options['csp_repost_days']) : 7;
        
        // バッチサイズを設定
        $batch_size = 50;
        $reposted_count = 0;
        
        // バッチ処理で古い投稿を再投稿
        do {
            $old_posts = get_posts(array(
                'post_status' => 'publish',
                'post_type' => 'post',
                'date_query' => array(
                    array(
                        'column' => 'post_date_gmt',
                        'before' => $repost_days . ' days ago',
                    ),
                ),
                'posts_per_page' => $batch_size,
            ));
            
            // これ以上古い投稿がない場合は終了
            if (empty($old_posts)) {
                break;
            }
            
            foreach ($old_posts as $post) {
                $post_data = array(
                    'ID' => $post->ID,
                    'post_date' => current_time('mysql'),
                    'post_date_gmt' => current_time('mysql', 1),
                );
                wp_update_post($post_data);
                $reposted_count++;
            }
            
            // メモリ解放
            wp_cache_flush();
        } while (count($old_posts) === $batch_size); // バッチサイズ分の投稿が取得できた場合のみ継続
        
        csp_log(sprintf('%d件の古い投稿を再投稿しました。', $reposted_count));
    } catch (Exception $e) {
        csp_log('古い投稿の再投稿エラー: ' . $e->getMessage(), 'ERROR');
    }
}

add_action('csp_hourly_event', 'csp_publish_drafts');
add_action('csp_daily_event', 'csp_repost_old_posts');

// 最後に削除された記事の数を取得
function csp_get_last_deleted_count() {
    return get_option('csp_last_deleted_count', 0);
}

// 重複チェックをスケジュール
function csp_schedule_duplicate_check() {
    try {
        if (!wp_next_scheduled('csp_weekly_event')) {
            wp_schedule_event(time(), 'weekly', 'csp_weekly_event');
        }
        csp_log('重複チェックスケジュールが正常に設定されました。');
    } catch (Exception $e) {
        csp_log('重複チェックスケジュール設定エラー: ' . $e->getMessage(), 'ERROR');
    }
}
add_action('wp', 'csp_schedule_duplicate_check');

// 重複チェックイベントのフック
add_action('csp_weekly_event', 'csp_check_for_duplicates');

// プラグインの初回有効化時にデフォルト設定を追加
register_activation_hook(__FILE__, 'csp_add_default_settings');

function csp_add_default_settings() {
    try {
        $default_options = array(
            'csp_interval' => 1,
            'csp_drafts_per_interval' => 1,
            'csp_repost_days' => 7,
            'csp_exclude_post_ids' => '',
        );

        if (!get_option('csp_options')) {
            update_option('csp_options', $default_options);
            csp_log('デフォルト設定が正常に追加されました。');
        }
    } catch (Exception $e) {
        csp_log('デフォルト設定追加エラー: ' . $e->getMessage(), 'ERROR');
    }
}
?>
