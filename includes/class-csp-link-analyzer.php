<?php
/**
 * カスタム予約投稿プラグイン - 内部リンク分析クラス
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('CSP_Link_Analyzer')) :

/**
 * 内部リンク分析クラス
 */
class CSP_Link_Analyzer {

    /**
     * インスタンス
     */
    private static $instance = null;

    /**
     * インスタンスを取得する
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * コンストラクタ
     */
    private function __construct() {
        $this->init_hooks();
    }

    /**
     * フックの初期化
     */
    private function init_hooks() {
        // ショートコードの登録
        add_shortcode('custom_posts_link', array($this, 'custom_posts_link_shortcode'));
        
        // D3.jsのスクリプト登録
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
    }

    /**
     * スクリプトの読み込み
     */
    public function enqueue_scripts() {
        // D3.jsを読み込み
        wp_register_script('d3', 'https://d3js.org/d3.v7.min.js', array(), '7.0.0', true);
    }

    /**
     * ショートコード[custom_posts_link]の実装
     */
    public function custom_posts_link_shortcode($atts) {
        // ショートコードの属性を取得
        $atts = shortcode_atts(array(
            'post_id' => get_the_ID(),
            'display' => 'graph', // graph または list
        ), $atts);
        
        $post_id = intval($atts['post_id']);
        $display_type = sanitize_text_field($atts['display']);
        
        // デバッグ用ログ出力
        if (function_exists('csp_log')) {
            csp_log('ショートコード呼び出し: post_id=' . $post_id . ', display_type=' . $display_type, 'DEBUG');
        }
        
        // 内部リンクを抽出（サイト全体の記事を対象）
        $internal_links = $this->extract_internal_links_from_site($post_id);
        
        // デバッグ用ログ出力
        if (function_exists('csp_log')) {
            csp_log('抽出された内部リンク: ' . print_r($internal_links, true), 'DEBUG');
        }
        
        // 表示タイプに応じて出力を変更
        if ($display_type === 'list') {
            return $this->render_internal_links_list($internal_links);
        } else {
            return $this->render_internal_links_graph($internal_links, $post_id);
        }
    }

    /**
     * サイト全体の記事から内部リンクを抽出する関数
     */
    public function extract_internal_links_from_site($current_post_id) {
        $links = array();
        
        // サイト全体の投稿と固定ページを取得
        $all_posts = get_posts(array(
            'post_type' => array('post', 'page'),
            'post_status' => 'publish',
            'posts_per_page' => -1,
        ));
        
        // 現在の投稿を取得
        $current_post = get_post($current_post_id);
        if (!$current_post) {
            return $links;
        }
        
        // 各投稿のコンテンツをチェック
        foreach ($all_posts as $post) {
            // 自分自身は除外
            if ($post->ID == $current_post_id) {
                continue;
            }
            
            // 投稿内容に現在の投稿へのリンクが含まれているかチェック
            if (strpos($post->post_content, get_permalink($current_post_id)) !== false) {
                $links[] = array(
                    'url' => get_permalink($post->ID),
                    'post_id' => $post->ID,
                    'title' => $post->post_title,
                    'anchor' => $post->post_title, // デフォルトのアンカーテキストとして投稿タイトルを使用
                );
            }
        }
        
        // 現在の投稿内のリンクを抽出
        $current_links = $this->extract_internal_links($current_post->post_content);
        $links = array_merge($links, $current_links);
        
        return $links;
    }

    /**
     * コンテンツから内部リンクを抽出する関数（アンカーテキスト対応版）
     */
    public function extract_internal_links($content) {
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
        if (function_exists('csp_log')) {
            csp_log('内部リンク抽出結果: ' . print_r($links, true), 'DEBUG');
        }
        
        return $links;
    }

    /**
     * 内部リンクをリスト形式で表示する関数
     */
    public function render_internal_links_list($links) {
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

    /**
     * グラフィカル表示の拡張機能
     */
    public function render_internal_links_graph($links, $current_post_id) {
        // デバッグ用ログ出力
        if (function_exists('csp_log')) {
            csp_log('グラフ描画関数呼び出し: リンク数=' . count($links) . ', 現在の投稿ID=' . $current_post_id, 'DEBUG');
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
                if (function_exists('csp_log')) {
                    csp_log('リンク先の投稿が存在しません: post_id=' . $link['post_id'], 'DEBUG');
                }
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
        if (function_exists('csp_log')) {
            csp_log('フィルタリング後のリンク数: ' . count($filtered_links), 'DEBUG');
        }
        
        // D3.jsを読み込み
        wp_enqueue_script('d3');
        
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
}

endif;