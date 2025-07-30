<?php
/**
 * Plugin Name:       WC Educpay Integration
 * Plugin URI:        https://ab.rio.br/
 * Description:       Plugin de Ensalamento WooCommerce (Educpay).
 * Version:           1.5.0 // VERSÃO ATUALIZADA
 * Author:            Agência AB Rio
 * Author URI:        https://wa.me/5521991242544
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Text Domain:       wc-educpay-integration
 * Domain Path:       /languages
 *
 * @package Educpay
 */

// Impede acesso direto
if (!defined('WPINC')) {
    die;
}

// Define o caminho do plugin (apenas se não definido)
if (!defined('WC_CADEMI_INTEGRATION_PATH')) {
    define('WC_CADEMI_INTEGRATION_PATH', plugin_dir_path(__FILE__));
}

// --- Inclui o arquivo da classe PRIMEIRO e verifica ---
$include_path = WC_CADEMI_INTEGRATION_PATH . 'includes/class-wc-cademi-integration.php';
$class_loaded_successfully = false; 

if (file_exists($include_path)) {
    require_once $include_path;
    if (class_exists('WCCademiIntegration')) {
        if (method_exists('WCCademiIntegration', 'init')) {
            $class_loaded_successfully = true; 
        } else {
            error_log('WC Educpay Debug: Arquivo 01 - ERRO FATAL: Classe existe, mas método ::init NÃO ENCONTRADO.');
        }
    } else {
         error_log('WC Educpay Debug: Arquivo 01 - ERRO FATAL: Classe WCCademiIntegration NÃO existe APÓS include.');
    }
} else {
    error_log('WC Educpay Integration Error: Arquivo principal da classe não encontrado em ' . $include_path);
    add_action('admin_notices', function() use ($include_path) {
        $message = sprintf(__('Erro Crítico WC Educpay: Arquivo de classe principal não encontrado em %s. O plugin não pode funcionar.', 'wc-educpay-integration'), '<code>' . esc_html($include_path) . '</code>');
        printf('<div class="notice notice-error"><p>%s</p></div>', $message);
    });
}

if ($class_loaded_successfully) {
    function wc_cademi_integration_init_plugin_wrapper() {
        WCCademiIntegration::init();
    }
    add_action('plugins_loaded', 'wc_cademi_integration_init_plugin_wrapper');

    register_activation_hook(__FILE__, array('WCCademiIntegration', 'activate'));
    register_deactivation_hook(__FILE__, array('WCCademiIntegration', 'deactivate'));
} else {
    error_log("WC Educpay Integration Error: Plugin não inicializado devido a erro no carregamento da classe WCCademiIntegration ou seu método init.");
    add_action('admin_notices', function() {
         echo '<div class="notice notice-error"><p>' . __('Erro Crítico no Plugin WC Educpay Integration: Falha ao carregar componentes essenciais. Verifique o log de erros do PHP.', 'wc-educpay-integration') . '</p></div>';
     });
}

?>