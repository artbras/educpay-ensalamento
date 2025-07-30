<?php
// Arquivo: includes/class-wc-cademi-integration.php
// MODIFICADO PARA LER DADOS DE PARTICIPANTE DO ITEM DO PEDIDO E USAR order_item_id COMO PARTE DO IDENTIFICADOR

if (!defined('ABSPATH')) { exit; }

class WCCademiIntegration {
    private static $instance = null;
    const LOCK_TRANSIENT_KEY = 'wc_educpay_api_lock'; // Não usado ativamente para lock de chamada
    const LAST_CALL_OPTION_KEY = '_wc_educpay_last_api_call_time';
    const LOCK_OPTION_KEY = 'wc_educpay_api_lock_option';
    const API_CALL_DELAY_SECONDS = 2.0;

    public static function init() {
        if (null === self::$instance) { self::$instance = new self(); }
        return self::$instance;
    }

    private function __construct() {
        if (is_admin()) {
            if (!defined('WC_CADEMI_INTEGRATION_PATH')) { error_log('WC Educpay Construct: Path constant WC_CADEMI_INTEGRATION_PATH missing.'); return; }
            $admin_class_path = WC_CADEMI_INTEGRATION_PATH . 'admin/class-wc-cademi-integration-admin.php';
            if (file_exists($admin_class_path)) {
                require_once $admin_class_path;
                if (class_exists('WCCademiIntegrationAdmin')) { new WCCademiIntegrationAdmin(); }
                else { error_log('WC Educpay Construct: Admin Class WCCademiIntegrationAdmin not found after include.'); }
            } else { error_log('WC Educpay Construct: Admin Class file not found: ' . $admin_class_path); }
        }
        add_action('woocommerce_order_status_changed', array($this, 'schedule_api_calls_on_status_change'), 10, 4);
        add_action('wc_educpay_send_api_data_action', array($this, 'process_scheduled_api_call'), 10, 9);
    }

    public static function activate() {
        global $wpdb;
        if (!isset($wpdb)) return;
        $table_name = $wpdb->prefix.'wc_cademi_logs';
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table_name} (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            time datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
            order_id bigint(20) NOT NULL,
            product_id bigint(20) UNSIGNED DEFAULT 0 NOT NULL,
            participant_identifier varchar(255) DEFAULT '' NOT NULL, /* Armazenará 'order_item_id_index' */
            status varchar(100) NOT NULL,
            response text NOT NULL,
            PRIMARY KEY  (id),
            KEY order_id (order_id),
            KEY product_participant (order_id,product_id,participant_identifier(20)) /* Aumentado para 20 */
        ) {$charset_collate};";
        if (!function_exists('dbDelta')) { if (file_exists(ABSPATH.'wp-admin/includes/upgrade.php')) require_once(ABSPATH.'wp-admin/includes/upgrade.php'); else return; }
        if (!function_exists('dbDelta')) return;
        dbDelta($sql);
        // Limpa options na ativação
        delete_option(self::LAST_CALL_OPTION_KEY);
        delete_option(self::LOCK_OPTION_KEY);
    }

    public static function deactivate() {
        delete_option(self::LAST_CALL_OPTION_KEY);
        delete_option(self::LOCK_OPTION_KEY);
    }

    /**
     * Agenda chamadas à API na mudança de status do pedido.
     * MODIFICADO: Lê dados dos participantes diretamente dos metadados do ITEM DO PEDIDO (_participant_name_X, _participant_email_X).
     * O participant_identifier agora é "order_item_id_index_no_item".
     */
    public function schedule_api_calls_on_status_change($order_id, $old_status, $new_status, $order) {
        if (!is_a($order, 'WC_Order')) return;

        $valid_statuses = ['completed','cancelled','refunded'];
        if (!in_array($new_status, $valid_statuses)) return;

        if (!function_exists('as_enqueue_async_action')) {
            $this->log_to_db($order_id, 0, '', $new_status . ' (Agendamento)', 'Erro Crítico: Action Scheduler não ativo.');
            return;
        }

        $options = get_option('wc_cademi_integration_options');
        $url = $options['api_url'] ?? '';
        $token = $options['api_token'] ?? '';

        if (empty($url) || empty($token)) {
            $this->log_to_db($order_id, 0, '', $new_status.' (Agendamento)', 'Erro: URL/Token da API não configurados.');
            return;
        }

        $status_map = array('completed'=>'aprovado','cancelled'=>'cancelado','refunded'=>'disputa');
        $status_para_envio = $status_map[$new_status] ?? $new_status;

        $items = $order->get_items();
        if (empty($items)) {
            $this->log_to_db($order_id, 0, '', $new_status.' (Agendamento)', 'Aviso: Pedido sem itens.');
            return;
        }

        $scheduling_log = [];

        foreach ($items as $item_id => $item) { // $item_id é o ID do item do pedido (wc_order_item_id)
            if (!is_a($item, 'WC_Order_Item_Product')) continue;

            $product = $item->get_product();
            if (!is_a($product,'WC_Product')) continue;

            $product_id = $product->get_id();
            $item_quantity = $item->get_quantity();

            $is_presencial = $product->get_meta('_is_presencial_no_integration', true);
            if ($is_presencial === 'yes') {
                $scheduling_log[] = "Prod:{$product_id} (Item:{$item_id})-Pulado (Presencial)";
                continue;
            }

            $course_type = $product->get_meta('_course_type', true);

            if (($course_type === 'individual' || $course_type === 'group') && $item_quantity > 0) {
                for ($i = 1; $i <= $item_quantity; $i++) {
                    // Identificador único para esta instância de participante/inscrição
                    $participant_identifier = "{$item_id}_{$i}"; // Ex: "154_1", "154_2"

                    $log_status_base = $new_status . " (Prod:{$product_id}, Item:{$item_id}, Part:{$i})";
                    
                    // Lê os metadados diretamente do item do pedido
                    $p_name  = $item->get_meta("_participant_name_{$i}", true);
                    $p_email = $item->get_meta("_participant_email_{$i}", true);

                    $f_name = !empty($p_name) ? $p_name : $order->get_billing_first_name().' '.$order->get_billing_last_name() . " (Fallback Part. {$i})";
                    // Para e-mail, é crucial que o específico do participante exista. Não usar fallback do pedido para múltiplos.
                    $f_email = !empty($p_email) ? $p_email : ''; 

                    if (!empty($f_email) && is_email($f_email)) {
                        $log_status_display = $log_status_base . ' - ' . $f_name . ' <' . $f_email . '>';
                        $log_status_display = mb_substr($log_status_display, 0, 95, 'UTF-8').(mb_strlen($log_status_display)>95?'...':'');
                        
                        $args = [$url, $token, $order_id, $product_id, $status_para_envio, $f_email, $f_name, $log_status_display, $participant_identifier];
                        as_enqueue_async_action('wc_educpay_send_api_data_action', $args, 'wc-educpay-integration');
                        $scheduling_log[] = "Prod:{$product_id} (Item:{$item_id}/Part:{$i}) Agendado";
                    } else {
                        $scheduling_log[] = "Prod:{$product_id} (Item:{$item_id}/Part:{$i}) Erro Email";
                        $this->log_to_db($order_id, $product_id, $participant_identifier, $log_status_base, "Erro: Email do participante {$i} (item {$item_id}) inválido ou ausente nos metadados do item (chaves: _participant_name_{$i}, _participant_email_{$i}).");
                    }
                }
            } else if ($item_quantity === 0 && ($course_type === 'individual' || $course_type === 'group')) {
                 $scheduling_log[] = "Prod:{$product_id} (Item:{$item_id}) Qtd Inválida";
                 $this->log_to_db($order_id, $product_id, "{$item_id}_qty_error", $new_status . " (Prod:{$product_id})", "Aviso: Produto ({$course_type}) com quantidade zero no item {$item_id} do pedido (agendamento).");
            }
        }

        if (!empty($scheduling_log)) {
            $this->log_to_db($order_id, 0, 'global_summary', $new_status . ' (Agendamento Global)', 'Status das Ações Agendadas: ' . implode('; ', $scheduling_log));
        }
    }

    /**
     * Processa chamada API agendada.
     * MODIFICADO: Interpreta participant_identifier como "order_item_id_index" para gerar api_codigo.
     */
    public function process_scheduled_api_call($url, $token, $order_id, $product_id, $status_api, $participant_email, $participant_name, $log_status_display, $participant_identifier) {
        if (empty($url)||empty($token)||empty($order_id)||empty($product_id)||empty($status_api)||empty($participant_email)||!is_email($participant_email)||empty($participant_identifier)) {
            $log_prefix_fallback = $log_status_display ?: "scheduled_error_oid_{$order_id}";
            $this->log_to_db($order_id ?: 0, $product_id ?: 0, $participant_identifier ?: 'unknown_ident', $log_prefix_fallback, 'Erro Crítico Interno: Dados inválidos/ausentes recebidos pela tarefa agendada. Verifique os argumentos.');
            error_log("WC Educpay Scheduler CRITICAL Error: Argumentos inválidos/ausentes. Order:{$order_id}, Prod:{$product_id}, Ident:'{$participant_identifier}', Email:'{$participant_email}'");
            return;
        }

        $order_id_abs = absint($order_id);
        $product_id_abs = absint($product_id);

        // Rate Limiting (sem alterações)
        $lock_option_key = self::LOCK_OPTION_KEY;
        $lock_acquired = false; $max_lock_wait_usec = 4500000; $retry_interval_usec = 300000; $start_wait_time = microtime(true);
        while (microtime(true)-$start_wait_time < ($max_lock_wait_usec/1000000.0)) {
            if (add_option($lock_option_key, time(), '', 'no')) { $lock_acquired = true; break; }
            usleep($retry_interval_usec);
        }
        if (!$lock_acquired) {
            $reschedule_time = time() + 11;
            if (function_exists('as_schedule_single_action')) {
                $args_to_pass_again = func_get_args();
                as_schedule_single_action($reschedule_time, 'wc_educpay_send_api_data_action', $args_to_pass_again, 'wc-educpay-integration');
                $this->log_to_db($order_id_abs, $product_id_abs, $participant_identifier, $log_status_display . ' (LockOpt)', 'Aviso: Processo concorrente. Reagendado (11s).');
            } else {
                $this->log_to_db($order_id_abs, $product_id_abs, $participant_identifier, $log_status_display . ' (LockOpt)', 'Erro: AS não pôde reagendar (lock).');
            }
            return;
        }

        try {
            $current_time_float = microtime(true);
            wp_cache_delete(self::LAST_CALL_OPTION_KEY, 'options');
            $last_call_time = (float) get_option(self::LAST_CALL_OPTION_KEY, 0.0);
            $elapsed = $current_time_float - $last_call_time;
            if ($elapsed < self::API_CALL_DELAY_SECONDS) {
                $wait_duration = self::API_CALL_DELAY_SECONDS - $elapsed;
                $this->log_to_db($order_id_abs, $product_id_abs, $participant_identifier, $log_status_display . ' (RateLimit)', sprintf('Aviso: Aguardando %.2f s.', $wait_duration));
                usleep((int)($wait_duration*1000000));
                $current_time_float = microtime(true);
            }
            update_option(self::LAST_CALL_OPTION_KEY, $current_time_float, false);
            delete_option($lock_option_key);
            $lock_acquired = false;

            // --- Geração do Código API (api_codigo) ---
            // participant_identifier é "order_item_id_index", ex: "154_1"
            $api_codigo_base = (string) $order_id_abs;
            $api_codigo = $api_codigo_base; // Padrão é o ID do pedido

            if (preg_match('/_(\d+)$/', $participant_identifier, $matches)) {
                $numeric_index_from_identifier = absint($matches[1]);
                if ($numeric_index_from_identifier > 0) {
                    $api_codigo = $api_codigo_base . sprintf('%02d', $numeric_index_from_identifier);
                }
            } else {
                // Se não houver _index no final, pode ser um caso legado ou um erro.
                // Para segurança, se não houver índice, usamos o order_id_abs apenas, ou podemos logar um aviso.
                // A lógica de agendamento deve sempre incluir um índice, como "ITEMID_1".
                error_log("WC Educpay process_scheduled_api_call: participant_identifier '{$participant_identifier}' não continha um índice numérico esperado no final. Usando order_id '{$api_codigo_base}' como api_codigo.");
            }
            
            $api_data = array(
                'token'         => $token,
                'codigo'        => $api_codigo,
                'status'        => $status_api,
                'produto_id'    => $product_id_abs,
                'cliente_email' => $participant_email,
                'cliente_nome'  => $participant_name,
            );

            error_log("WC Educpay Scheduler: Enviando para API. OrderID: {$order_id_abs}, ProdID: {$product_id_abs}, Participante Ident: {$participant_identifier}, API Codigo: {$api_codigo}. Dados: " . print_r($api_data, true));

            try {
                $response = $this->send_cademi_data($url, $api_data);
                $this->log_to_db($order_id_abs, $product_id_abs, $participant_identifier, $log_status_display, $response);
            } catch (\Exception $e) {
                $error_message = 'Erro agendado ao enviar dados: ' . $e->getMessage();
                $this->log_to_db($order_id_abs, $product_id_abs, $participant_identifier, $log_status_display . ' (Exceção API)', $error_message);
                error_log("WC Educpay Scheduled Exception: {$error_message} para Order:{$order_id_abs}, Ident:{$participant_identifier}");
            }
        } finally {
            if ($lock_acquired) delete_option($lock_option_key);
        }
    }

    // send_cademi_data e log_to_db permanecem como na versão 1.4.0 (fornecida anteriormente)
    // Assegure-se que a coluna participant_identifier na tabela wc_cademi_logs seja varchar(255)
    // e que o KEY product_participant (order_id,product_id,participant_identifier(XX)) tenha um tamanho adequado para participant_identifier (ex: 20 ou mais).
    // A alteração na tabela de ativação já sugeriu aumentar para 20.
    private function send_cademi_data($url, $data) {
        $post_fields=http_build_query($data);
        $ua='WordPress/'.get_bloginfo('version').'; '.get_bloginfo('url').' (WC Educpay)';
        $args=['body'=>$post_fields,'timeout'=>20,'redirection'=>5,'httpversion'=>'1.0','blocking'=>true,'headers'=>['Content-Type'=>'application/x-www-form-urlencoded','User-Agent'=>$ua],'cookies'=>[],'sslverify'=>true];
        $response=wp_remote_post($url, $args); if(is_wp_error($response)){$em=$response->get_error_message(); throw new \Exception("Erro WP: ".$em);}
        $httpcode=wp_remote_retrieve_response_code($response); $body=wp_remote_retrieve_body($response);
        $body_safe=is_string($body)?$body:''; $log_body = mb_substr($body_safe, 0, 500).(mb_strlen($body_safe)>500?'...':'');

        switch($httpcode){
            case 200: case 201:
                 $json=json_decode($body);
                 if(json_last_error()===JSON_ERROR_NONE && is_object($json)){
                     if((isset($json->success)&&$json->success===false)||(isset($json->error))){ $em = $json->msg ?? ($json->error->message ?? ($json->error ?? 'Erro JSON desconhecido')); throw new \Exception("Erro API({$httpcode}): ".$em); }
                     if(isset($json->success)&&$json->success===true){ return "Sucesso: ".($json->msg??'OK'); }
                     error_log("WC Educpay send_cademi_data: HTTP {$httpcode} com JSON não reconhecido: " . $log_body);
                     return "Código {$httpcode} (JSON?): ".$log_body;
                 } else {
                     error_log("WC Educpay send_cademi_data: HTTP {$httpcode} sem JSON válido: " . $log_body);
                     return "Código {$httpcode}: ".$log_body;
                 }
            case 401: throw new \Exception("Erro 401: Autenticação. Verifique Token.");
            case 403: throw new \Exception("Erro 403: Permissão Negada.");
            case 409: $json_c=json_decode($body); if(json_last_error()===JSON_ERROR_NONE&&isset($json_c->msg)){throw new \Exception("Conflito(409): ".$json_c->msg);}else{throw new \Exception("Conflito(409)-Resp Inválida: ".mb_substr($body_safe,0,200));}
            default: $ed=!empty($body_safe)?" - Resp: ".mb_substr($body_safe,0,200).(mb_strlen($body_safe)>200?'...':''):""; throw new \Exception("Erro HTTP {$httpcode}{$ed}");
        }
    }

    public function log_to_db($order_id, $product_id, $participant_identifier, $status, $response) {
        global $wpdb;
        if (!isset($wpdb)) return;
        $table_name = $wpdb->prefix . 'wc_cademi_logs';
        $response_text = (is_array($response) || is_object($response)) ? print_r($response, true) : (string) $response;
        $status_text = mb_substr((string)$status, 0, 100, 'UTF-8');
        $pi_text = mb_substr((string)$participant_identifier, 0, 255, 'UTF-8');

        $inserted = $wpdb->insert( $table_name, array(
            'time'=>current_time('mysql', 1), 'order_id'=>absint($order_id), 'product_id'=>absint($product_id),
            'participant_identifier'=>$pi_text, 'status'=>$status_text, 'response'=>$response_text
        ), array('%s','%d','%d','%s','%s','%s') );
        if (false === $inserted && !empty($wpdb->last_error)) { error_log("WC Educpay DB Log Error: Pedido {$order_id}, Ident:{$pi_text}. Erro: " . $wpdb->last_error); }
    }

} // Fim da classe
?>