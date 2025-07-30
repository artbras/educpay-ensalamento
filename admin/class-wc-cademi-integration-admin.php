<?php

if (!defined('ABSPATH')) {
    exit; // Evita acesso direto
}

/**
 * Classe que gerencia a área administrativa do plugin WC Educpay Integration.
 * MODIFICADO: para lidar com o novo formato de participant_identifier (order_item_id_index) no reenvio de logs.
 */
class WCCademiIntegrationAdmin {

    public function __construct() {
        add_action('admin_menu', array($this, 'add_plugin_page'));
        add_action('admin_init', array($this, 'page_init'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_log_resend_script'));
        add_action('wp_ajax_wc_educpay_resend_log_entry', array($this, 'handle_ajax_resend_log_entry'));
    }

    // As funções add_plugin_page, create_admin_page, page_init, sanitize_options,
    // api_url_callback, api_token_callback, logs_page, display_logs,
    // display_instructions, display_products, enqueue_log_resend_script
    // permanecem como na versão 1.4.0 que enviei anteriormente.
    // Assegure que display_products reflita a mudança para "Grupo (Qtd. do Carrinho)".
    // Por brevidade, vou omiti-las e focar na handle_ajax_resend_log_entry.
    // Se precisar delas, me avise.

    // --- COPIAR DE VERSÃO ANTERIOR ---
    public function add_plugin_page() { /* ...código da versão 1.4.0... */ 
        add_menu_page(__('WC Educpay Integration', 'wc-educpay-integration'), __('Educpay Int.', 'wc-educpay-integration'), 'manage_options', 'wc-cademi-integration', array($this, 'create_admin_page'), 'dashicons-randomize', 81 );
        add_submenu_page('wc-cademi-integration', __('Configurações da API', 'wc-educpay-integration'), __('Configurações', 'wc-educpay-integration'), 'manage_options', 'wc-cademi-integration', array($this, 'create_admin_page'));
        add_submenu_page('wc-cademi-integration', __('Listar Cursos no Woo', 'wc-educpay-integration'), __('Listar Cursos Woo', 'wc-educpay-integration'), 'manage_options', 'wc-cademi-integration-products', array($this, 'display_products'));
        add_submenu_page('wc-cademi-integration', __('Instruções de Configuração', 'wc-educpay-integration'), __('Instruções', 'wc-educpay-integration'), 'manage_options', 'wc-cademi-integration-instructions', array($this, 'display_instructions'));
        add_submenu_page('wc-cademi-integration', __('Logs da Integração', 'wc-educpay-integration'), __('Logs da Integração', 'wc-educpay-integration'), 'manage_options', 'wc-cademi-integration-logs', array($this, 'logs_page'));
    }
    public function create_admin_page() { /* ...código da versão 1.4.0... */ ?>
        <div class="wrap">
            <h1><?php _e('WC Educpay Integration - Configurações da API', 'wc-educpay-integration'); ?></h1>
            <p><?php _e('Informe a URL e o Token fornecidos pela plataforma externa (Educpay/Cademi).', 'wc-educpay-integration'); ?> <a href="<?php echo esc_url(admin_url('admin.php?page=wc-cademi-integration-instructions')); ?>"><?php _e('Ver instruções detalhadas', 'wc-educpay-integration'); ?></a></p>
            <form method="post" action="options.php">
                <?php settings_fields('wc_cademi_integration_group'); do_settings_sections('wc-cademi-integration-admin'); submit_button(__('Salvar Alterações', 'wc-educpay-integration')); ?>
            </form>
        </div>
    <?php }
    public function page_init() { /* ...código da versão 1.4.0... */ 
        register_setting('wc_cademi_integration_group', 'wc_cademi_integration_options', array($this, 'sanitize_options'));
        add_settings_section('wc_cademi_integration_section_api', __('Credenciais da API', 'wc-educpay-integration'), null, 'wc-cademi-integration-admin');
        add_settings_field('api_url', __('URL da API (Webhook)', 'wc-educpay-integration'), array($this, 'api_url_callback'), 'wc-cademi-integration-admin', 'wc_cademi_integration_section_api');
        add_settings_field('api_token', __('Token da API', 'wc-educpay-integration'), array($this, 'api_token_callback'), 'wc-cademi-integration-admin', 'wc_cademi_integration_section_api');
    }
    public function sanitize_options($input) { /* ...código da versão 1.4.0... */ $sanitized_input = array(); if (isset($input['api_url'])) { $sanitized_input['api_url'] = esc_url_raw(trim($input['api_url'])); } if (isset($input['api_token'])) { $sanitized_input['api_token'] = sanitize_text_field(trim($input['api_token'])); } return $sanitized_input; }
    public function api_url_callback() { /* ...código da versão 1.4.0... */ $options = get_option('wc_cademi_integration_options'); $url = isset($options['api_url']) ? $options['api_url'] : ''; printf('<input type="url" id="api_url" name="wc_cademi_integration_options[api_url]" value="%s" class="regular-text" required placeholder="https://..." />', esc_attr($url)); echo '<p class="description">' . __('A URL completa para onde os dados do pedido serão enviados via POST.', 'wc-educpay-integration') . '</p>'; }
    public function api_token_callback() { /* ...código da versão 1.4.0... */ $options = get_option('wc_cademi_integration_options'); $token = isset($options['api_token']) ? $options['api_token'] : ''; printf('<input type="text" id="api_token" name="wc_cademi_integration_options[api_token]" value="%s" class="regular-text" required />', esc_attr($token)); echo '<p class="description">' . __('O token secreto para autenticação na API.', 'wc-educpay-integration') . '</p>'; }
    public function logs_page() { /* ...código da versão 1.4.0... */ ?>
        <div class="wrap">
            <h1><?php _e('Logs da Integração Educpay/Cademi', 'wc-educpay-integration'); ?></h1>
            <p><?php _e('Exibe os últimos 100 registros de envio para a API. Clique no pedido para ver detalhes.', 'wc-educpay-integration'); ?></p>
            <?php wp_nonce_field('wc_educpay_resend_log_nonce_action', 'wc_educpay_resend_log_nonce'); ?>
            <div id="logs-accordion"><?php $this->display_logs(); ?></div>
        </div>
        <style>.accordion-item { margin-bottom: 10px; border: 1px solid #ddd; border-radius: 3px; overflow: hidden; } .accordion-header { background: #f0f0f1; color: #3c434a; padding: 10px 15px; cursor: pointer; font-weight: bold; border-bottom: 1px solid #ddd; } .accordion-header:hover { background: #e5e5e5; } .accordion-content { background: #fff; padding: 15px; border-top: 1px solid #ddd; } .accordion-content .log-entry { margin-bottom: 15px; padding-bottom: 15px; border-bottom: 1px dashed #ccc; display: flex; flex-wrap: wrap; align-items: flex-start; justify-content: space-between; } .accordion-content .log-entry:last-child { border-bottom: none; margin-bottom: 0; padding-bottom: 0;} .log-details { flex-grow: 1; margin-right: 15px; } .log-details p { margin: 0 0 3px 0; padding: 0; line-height: 1.4; } .log-details strong { font-weight: 600; } .log-response { margin-left: 15px; margin-top: 0; margin-bottom: 5px; font-family: monospace; background: #f5f5f5; padding: 8px; border: 1px solid #eee; border-radius: 3px; word-wrap: break-word; white-space: pre-wrap; font-size: 12px; flex-basis: 100%; order: 3; } .log-actions { margin-left: 15px; margin-top: 5px; order: 2; text-align: right; } .log-actions button { vertical-align: middle; margin-left: 5px; } .log-actions .spinner { visibility: hidden; opacity: 0; float: none !important; vertical-align: middle !important; transition: all 0.3s ease; margin-left: 3px;} .log-actions .spinner.is-active { visibility: visible; opacity: 1; } .log-actions .resend-status { margin-left: 8px; font-style: italic; font-size: smaller; } </style>
        <script>document.addEventListener('DOMContentLoaded', function() { const headers = document.querySelectorAll('.accordion-header'); headers.forEach(header => { header.addEventListener('click', function() { const content = this.nextElementSibling; if(content) { const isHidden = content.style.display === 'none' || content.style.display === ''; content.style.display = isHidden ? 'block' : 'none';}}); if (header.nextElementSibling) { header.nextElementSibling.style.display = 'none'; } }); });</script>
    <?php }
    public function display_logs() { /* ...código da versão 1.4.0... */ 
        global $wpdb; $table_name = $wpdb->prefix . 'wc_cademi_logs';
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name)) != $table_name) { echo '<p>' . __('Erro: Tabela de logs não encontrada.', 'wc-educpay-integration') . '</p>'; return; }
        $logs = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table_name ORDER BY id DESC LIMIT %d", 100));
        if (empty($logs)) { echo '<p>' . __('Nenhum log encontrado.', 'wc-educpay-integration') . '</p>'; return; }
        $logs_by_order = []; foreach ($logs as $log) { $logs_by_order[$log->order_id][] = $log; }
        foreach ($logs_by_order as $order_id => $order_logs) {
            usort($order_logs, function($a, $b) { $ta = strtotime($a->time); $tb = strtotime($b->time); return ($ta == $tb) ? $b->id - $a->id : $tb - $ta; });
            $first_log = $order_logs[0]; echo '<div class="accordion-item"><h3 class="accordion-header">' . sprintf(__('Pedido #%d - Último Log: %s', 'wc-educpay-integration'), $order_id, esc_html($first_log->status)) . '</h3><div class="accordion-content" style="display: none;">';
            foreach ($order_logs as $log_entry) {
                $response_string = stripslashes($log_entry->response); $response_display = esc_html($response_string); $is_success = (stripos($response_string, 'Sucesso:') === 0); $status_icon = $is_success ? '✔️' : '❌';
                try { $log_time_obj = new DateTime($log_entry->time, new DateTimeZone('UTC')); $log_time_obj->setTimezone(new DateTimeZone(wp_timezone_string())); $formatted_time = $log_time_obj->format('d/m/Y H:i:s'); } catch (Exception $e) { $formatted_time = $log_entry->time . ' (UTC)'; }
                $is_scheduling_or_manual_log = (strpos($log_entry->status, '(Agendamento)') !== false) || (strpos($log_entry->status, '(Reenvio Manual)') !== false) || (strpos($log_entry->status, '(Reenvio Agendado)') !== false);
                $prod_id = isset($log_entry->product_id) ? $log_entry->product_id : 0; $part_ident = isset($log_entry->participant_identifier) ? $log_entry->participant_identifier : '';
                $show_resend_button = !$is_scheduling_or_manual_log && !empty($prod_id) && !empty($part_ident);
                echo '<div class="log-entry"><div class="log-details"><p style="margin-bottom: 2px;"><strong>' . esc_html($formatted_time) . ' (' . esc_html($log_entry->status) . '):</strong> ' . $status_icon . '</p><p class="log-response">' . nl2br($response_display) . '</p></div>';
                if ($show_resend_button) { echo '<div class="log-actions"><button type="button" class="button button-secondary button-small wc-educpay-log-resend-button" data-log-id="'.esc_attr($log_entry->id).'" data-order-id="'.esc_attr($order_id).'" data-product-id="'.esc_attr($prod_id).'" data-participant-index="'.esc_attr($part_ident).'" title="'.esc_attr__('Reagendar envio para este participante/item.', 'wc-educpay-integration').'"><span class="dashicons dashicons-controls-repeat" style="vertical-align: text-bottom;"></span> '.esc_html__('Reenviar', 'wc-educpay-integration').'</button><span class="spinner"></span><span class="resend-status"></span></div>'; }
                echo '</div>';
            } echo '</div></div>';
        } echo '<p style="margin-top: 20px;"><small>' . sprintf(__('Logs antigos podem precisar ser removidos manualmente do banco de dados (tabela: %s).', 'wc-educpay-integration'), esc_html($table_name)) . '</small></p>';
    }
    public function display_instructions() { /* ...código da versão 1.4.0... */ ?> <div class="wrap"> <h1><?php _e('Instruções de Configuração - WC Educpay Integration', 'wc-educpay-integration'); ?></h1> <p><?php _e('Siga os passos abaixo para configurar a integração do WooCommerce com a plataforma Educpay/Cademi.', 'wc-educpay-integration'); ?></p> <h2><?php _e('1. Requisitos Essenciais:', 'wc-educpay-integration'); ?></h2> <ul><li><strong>WooCommerce</strong></li><li><strong>WooCommerce Curso Checkout Fields (Plugin 2)</strong>: Configurado para que a quantidade de participantes seja baseada na quantidade do item no carrinho.</li><li><strong>Plataforma Educpay/Cademi</strong>: Acesso e credenciais da API (URL e Token).</li></ul> <h2><?php _e('2. Configuração da API (Neste Plugin):', 'wc-educpay-integration'); ?></h2> <ul><li><?php printf(__('Acesse a página de %s.', 'wc-educpay-integration'), '<a href="'.esc_url(admin_url('admin.php?page=wc-cademi-integration')).'">'.__('Configurações da Integração', 'wc-educpay-integration').'</a>'); ?></li><li><strong><?php _e('URL da API (Webhook):', 'wc-educpay-integration'); ?></strong> <?php _e('Informe a URL completa fornecida pela Educpay/Cademi.', 'wc-educpay-integration'); ?></li><li><strong><?php _e('Token da API:', 'wc-educpay-integration'); ?></strong> <?php _e('Informe o Token secreto fornecido pela Educpay/Cademi.', 'wc-educpay-integration'); ?></li></ul> <h2><?php _e('3. Configuração dos Produtos no WooCommerce:', 'wc-educpay-integration'); ?></h2> <ul><li><?php _e('Ao editar um produto no WooCommerce:', 'wc-educpay-integration'); ?></li><li><?php _e('Na caixa "Configuração de Curso/Inscrição":', 'wc-educpay-integration'); ?><ul><li>Marque "<strong><?php _e('Curso Presencial (Não integrar com Educpay)', 'woocommerce-curso-checkout-fields'); ?></strong>" se este produto NÃO deve enviar dados para a API.</li><li>Selecione o "<strong><?php _e('Tipo de Inscrição:', 'woocommerce-curso-checkout-fields'); ?></strong>": "Individual" ou "Em Grupo". Em ambos os casos, o número de participantes será determinado pela quantidade do item no carrinho.</li></ul></li><li><?php printf(__('Utilize o ID do Produto (listado em %s) para configurar na plataforma Educpay/Cademi.', 'wc-educpay-integration'), '<a href="'.esc_url(admin_url('admin.php?page=wc-cademi-integration-products')).'">'.__('Listar Cursos Woo', 'wc-educpay-integration').'</a>'); ?></li></ul> <h2><?php _e('4. Configurações Recomendadas no WooCommerce:', 'wc-educpay-integration'); ?></h2><ul><li><?php printf(__('Em %s, é recomendado desativar pedidos sem conta e ativar criação de conta no checkout.', 'wc-educpay-integration'), '<a href="'.esc_url(admin_url('admin.php?page=wc-settings&tab=account')).'">'.__('WC > Config > Contas e privacidade', 'wc-educpay-integration').'</a>'); ?></li><li>Verifique o status de pedido que confirma o pagamento em seu gateway (ex: "Concluído").</li></ul> <h2><?php _e('5. Funcionamento:', 'wc-educpay-integration'); ?></h2><ul><li>Mudança de status do pedido (Concluído, Cancelado, Reembolsado) agenda o envio dos dados para a API.</li><li>Os dados de cada participante (conforme quantidade no carrinho) são enviados individualmente.</li><li>Envios são processados em segundo plano com controle de taxa.</li><li>Resultados aparecem nos "<?php _e('Logs da Integração', 'wc-educpay-integration'); ?>".</li><li>Botão "<?php _e('Reenviar', 'wc-educpay-integration'); ?>" nos logs permite reenviar dados de um participante específico.</li></ul></div> <?php }
    public function display_products() { /* ...código da versão 1.4.0 com a modificação já indicada para "Grupo (Qtd. do Carrinho)"... */ 
        ?> <div class="wrap"> <h1><?php _e('Cursos Cadastrados no WooCommerce', 'wc-educpay-integration'); ?></h1> <p><?php _e('Utilize o ID do Curso/Produto abaixo para configurar a entrega correspondente na plataforma externa.', 'wc-educpay-integration'); ?></p><br><?php $args = array( 'post_type'=>'product', 'posts_per_page'=>-1, 'post_status'=>'publish', 'orderby'=>'title', 'order'=>'ASC', ); $products_query = new WP_Query($args); if ($products_query->have_posts()) { echo '<table class="wp-list-table widefat fixed striped products"><thead><tr><th scope="col" class="manage-column column-primary">'.__('ID','wc-educpay-integration').'</th><th scope="col" class="manage-column">'.__('Nome','wc-educpay-integration').'</th><th scope="col" class="manage-column">'.__('Preço','wc-educpay-integration').'</th><th scope="col" class="manage-column">'.__('Tipo Configurado','wc-educpay-integration').'</th></tr></thead><tbody id="the-list">'; while ($products_query->have_posts()) { $products_query->the_post(); $product_id = get_the_ID(); $_product = wc_get_product($product_id); if (!$_product) continue; $product_name = $_product->get_name(); $product_price = wc_price($_product->get_price()); $is_presencial = $_product->get_meta('_is_presencial_no_integration')==='yes'; $course_type_meta = $_product->get_meta('_course_type', true); $tipo_configurado = __('Não configurado','wc-educpay-integration'); if($is_presencial){$tipo_configurado=__('Presencial (Sem API)','wc-educpay-integration');}elseif($course_type_meta==='individual'){$tipo_configurado=__('Individual (Qtd. do Carrinho)','wc-educpay-integration');}elseif($course_type_meta==='group'){$tipo_configurado=__('Em Grupo (Qtd. do Carrinho)','wc-educpay-integration');} echo '<tr><td class="column-primary has-row-actions" data-colname="ID"><strong>'.esc_html($product_id).'</strong>'; echo '<div class="row-actions"><span class="edit"><a href="'.get_edit_post_link($product_id).'">'.__('Editar').'</a></span></div>'; echo '<button type="button" class="toggle-row"><span class="screen-reader-text">Details</span></button>'; echo '</td>'; echo '<td data-colname="Nome">'.esc_html($product_name).'</td>'; echo '<td data-colname="Preço">'.wp_kses_post($product_price).'</td>'; echo '<td data-colname="Tipo Configurado">'.esc_html($tipo_configurado).'</td>'; echo '</tr>'; } echo '</tbody></table>'; } else { echo '<p>'.__('Nenhum produto encontrado.', 'wc-educpay-integration').'</p>'; } wp_reset_postdata(); ?> </div> <?php
    }
    public function enqueue_log_resend_script($hook) { /* ...código da versão 1.4.0... */ 
        $current_screen = get_current_screen(); $admin_page_hook = get_plugin_page_hookname('wc-cademi-integration-logs', 'wc-cademi-integration');
        if ($hook === $admin_page_hook && $current_screen && $current_screen->id === $admin_page_hook) {
            $script_path = 'js/log-resend-script.js'; $script_dir = plugin_dir_path(__FILE__); $plugin_admin_url = plugin_dir_url(__FILE__); $script_file_path = $script_dir . $script_path; $script_url = $plugin_admin_url . $script_path;
            if (file_exists($script_file_path)) {
                wp_enqueue_script('wc-educpay-log-resend-script', $script_url, array('jquery'), '1.2', true);
                wp_localize_script('wc-educpay-log-resend-script', 'wcEducpayLogResend', array( 'ajax_url' => admin_url('admin-ajax.php'), 'resending_text' => __('Reagendando...', 'wc-educpay-integration'), 'resend_text' => __('Reenviar', 'wc-educpay-integration'), 'nonce' => wp_create_nonce('wc_educpay_resend_log_nonce_action') ));
            } else { error_log('WC Educpay Integration Error: Arquivo JS ' . esc_html($script_path) . ' não encontrado em ' . esc_html($script_file_path)); }
        }
    }
    // --- FIM DA CÓPIA ---

    /**
     * Manipula a requisição AJAX para reenviar UM participante/item específico do log.
     * MODIFICADO: Interpreta participant_identifier como "order_item_id_index"
     */
    public function handle_ajax_resend_log_entry() {
        $order_id_from_post = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
        $nonce = isset($_POST['nonce']) ? sanitize_key($_POST['nonce']) : '';

        if (!wp_verify_nonce($nonce, 'wc_educpay_resend_log_nonce_action')) {
            wp_send_json_error(__('Falha na verificação de segurança (nonce).', 'wc-educpay-integration'), 403);
            return;
        }
        if (!current_user_can('manage_woocommerce') || ($order_id_from_post && !current_user_can('edit_shop_order', $order_id_from_post)) ) {
            wp_send_json_error(__('Permissão negada para esta ação.', 'wc-educpay-integration'), 403);
            return;
        }

        $log_id = isset($_POST['log_id']) ? absint($_POST['log_id']) : 0;
        // $product_id_from_log é o ID do produto WooCommerce, não o ID do item do pedido.
        $product_id_from_log = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0; 
        // $composite_participant_identifier será "order_item_id_index_no_item", ex: "154_1"
        $composite_participant_identifier = isset($_POST['participant_index']) ? sanitize_text_field($_POST['participant_index']) : '';

        if (empty($order_id_from_post) || empty($product_id_from_log) || empty($composite_participant_identifier)) {
            wp_send_json_error(__('Dados inválidos recebidos para reenvio (pedido, produto ou identificador do participante ausente).', 'wc-educpay-integration'), 400);
            return;
        }

        $order = wc_get_order($order_id_from_post);
        if (!$order) {
            wp_send_json_error(__('Pedido não encontrado.', 'wc-educpay-integration'), 404);
            return;
        }

        $options = get_option('wc_cademi_integration_options');
        $url = $options['api_url'] ?? '';
        $token = $options['api_token'] ?? '';
        if (empty($url) || empty($token)) {
            wp_send_json_error(__('URL ou Token da API não configurados.', 'wc-educpay-integration'), 500);
            return;
        }

        $current_status = $order->get_status();
        $current_status_clean = str_replace('wc-', '', $current_status);
        $status_map = array('completed'=>'aprovado','cancelled'=>'cancelado','refunded'=>'disputa');
        $status_para_envio = $status_map[$current_status_clean] ?? $current_status_clean;

        // Parse composite_participant_identifier "order_item_id_index"
        $parsed_order_item_id = 0;
        $parsed_index_in_item = 0;

        if (preg_match('/^(\d+)_(\d+)$/', $composite_participant_identifier, $matches)) {
            $parsed_order_item_id = absint($matches[1]); // ID do item do pedido
            $parsed_index_in_item = absint($matches[2]); // Índice do participante DENTRO do item
        } else {
            wp_send_json_error(__('Formato do identificador do participante inválido para reenvio.', 'wc-educpay-integration'), 400);
            return;
        }

        if (empty($parsed_order_item_id) || empty($parsed_index_in_item)) {
            wp_send_json_error(__('Não foi possível parsear o ID do item do pedido ou o índice do participante a partir do identificador.', 'wc-educpay-integration'), 400);
            return;
        }

        // Carrega o item do pedido específico
        $item = $order->get_item($parsed_order_item_id);
        if (!$item || !is_a($item, 'WC_Order_Item_Product')) {
            wp_send_json_error(sprintf(__('Item do pedido (ID: %d) não encontrado ou não é um produto.', 'wc-educpay-integration'), $parsed_order_item_id), 404);
            return;
        }
        
        // O $product_id_from_log deve coincidir com $item->get_product_id(). Pode ser usado para validação se desejar.
        // $actual_product_id_from_item = $item->get_product_id();
        // if ($product_id_from_log != $actual_product_id_from_item) {
        // wp_send_json_error(__( 'Inconsistência de ID de produto entre log e item do pedido.', 'wc-educpay-integration'), 500);
        // return;
        // }

        // Chaves de metadados simples para o item do pedido
        $meta_key_name  = "_participant_name_{$parsed_index_in_item}";
        $meta_key_email = "_participant_email_{$parsed_index_in_item}";
        
        $p_name  = $item->get_meta($meta_key_name, true);
        $p_email = $item->get_meta($meta_key_email, true);

        $final_name = !empty($p_name) ? $p_name : $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() . " (Fallback Part. {$parsed_index_in_item})";
        $final_email = !empty($p_email) ? $p_email : '';

        if (empty($final_email) || !is_email($final_email)) {
            $error_msg_email = sprintf(
                __('Email do participante %1$d (item ID %2$d) ausente ou inválido nos metadados do item. Chaves tentadas: %3$s, %4$s.', 'wc-educpay-integration'),
                $parsed_index_in_item,
                $parsed_order_item_id,
                esc_html($meta_key_name),
                esc_html($meta_key_email)
            );
            if(class_exists('WCCademiIntegration')) WCCademiIntegration::init()->log_to_db($order_id_from_post, $product_id_from_log, $composite_participant_identifier, $current_status_clean . ' (Erro Reenvio Meta Item)', "Falha: " . $error_msg_email);
            wp_send_json_error($error_msg_email, 400);
            return;
        }
        
        $log_status_prefix_base = $current_status_clean;
        $log_status_prefix = "{$log_status_prefix_base} (Reenvio Log#{$log_id} Prod:{$product_id_from_log}, Item:{$parsed_order_item_id}, Part:{$parsed_index_in_item})";
        $log_status_display = $log_status_prefix . ' - ' . $final_name . ' <' . $final_email . '>';
        $log_status_display = mb_substr($log_status_display, 0, 95, 'UTF-8') . (mb_strlen($log_status_display) > 95 ? '...' : '');

        if (!function_exists('as_enqueue_async_action')) {
            wp_send_json_error(__('Action Scheduler não está ativo.', 'wc-educpay-integration'), 500);
            return;
        }

        $args_to_pass = [
            $url,
            $token,
            $order_id_from_post,
            $product_id_from_log, // O ID do produto original do log
            $status_para_envio,
            $final_email,
            $final_name,
            $log_status_display,
            $composite_participant_identifier // O identificador original "order_item_id_index" do log
        ];
        $action_id = as_enqueue_async_action('wc_educpay_send_api_data_action', $args_to_pass, 'wc-educpay-integration');

        if ($action_id) {
            if(class_exists('WCCademiIntegration')) WCCademiIntegration::init()->log_to_db($order_id_from_post, $product_id_from_log, $composite_participant_identifier, $log_status_display . ' (Reenvio Agendado)', "Ação reagendada manualmente (ID Nova Ação: {$action_id}). Log Original ID: {$log_id}");
            wp_send_json_success(__('Ação de reenvio para o participante foi agendada com sucesso.', 'wc-educpay-integration'));
        } else {
            if(class_exists('WCCademiIntegration')) WCCademiIntegration::init()->log_to_db($order_id_from_post, $product_id_from_log, $composite_participant_identifier, $log_status_display . ' (Erro Reenvio Agend.)', "Falha ao agendar ação de reenvio. Log Original ID: {$log_id}");
            wp_send_json_error(__('Falha ao agendar a ação de reenvio.', 'wc-educpay-integration'), 500);
        }
    }

} // Fim da Classe WCCademiIntegrationAdmin
?>